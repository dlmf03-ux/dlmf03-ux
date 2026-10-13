import json
import mimetypes
import time
from collections import defaultdict, deque
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlparse

from . import config
from .openai_client import OpenAIError, answer
from .rag import KnowledgeBase

STATIC = config.ROOT / "static"
KB = KnowledgeBase()
REQUESTS = defaultdict(deque)


def allowed(origin: str | None) -> bool:
    return not origin or origin.rstrip("/") in config.ALLOWED_ORIGINS


class Handler(BaseHTTPRequestHandler):
    server_version = "KnowledgeAssistantDemo/1.0"

    def log_message(self, fmt, *args):
        # Do not log request bodies or user questions.
        print(f"{self.address_string()} {self.command} {urlparse(self.path).path}")

    def _cors(self):
        origin = self.headers.get("Origin")
        if origin and allowed(origin):
            self.send_header("Access-Control-Allow-Origin", origin)
            self.send_header("Vary", "Origin")
        self.send_header("Access-Control-Allow-Methods", "POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")

    def json_response(self, status: int, payload: dict):
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self._cors()
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.end_headers()
        self.wfile.write(body)

    def do_OPTIONS(self):
        if not allowed(self.headers.get("Origin")):
            return self.json_response(HTTPStatus.FORBIDDEN, {"error": "origin_not_allowed"})
        self.send_response(HTTPStatus.NO_CONTENT)
        self._cors()
        self.end_headers()

    def do_GET(self):
        path = urlparse(self.path).path
        if path == "/health":
            return self.json_response(200, {"ok": True, "openai_configured": bool(config.OPENAI_API_KEY)})
        files = {"/widget.js": "widget.js", "/widget.css": "widget.css", "/demo": "demo.html", "/": "demo.html"}
        if path not in files:
            return self.send_error(404)
        file = STATIC / files[path]
        body = file.read_bytes()
        self.send_response(200)
        self.send_header("Content-Type", (mimetypes.guess_type(file.name)[0] or "application/octet-stream") + "; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "public, max-age=300")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.end_headers()
        self.wfile.write(body)

    def _rate_limited(self) -> bool:
        ip = self.client_address[0]
        now = time.time()
        bucket = REQUESTS[ip]
        while bucket and bucket[0] < now - 60:
            bucket.popleft()
        if len(bucket) >= config.RATE_LIMIT:
            return True
        bucket.append(now)
        return False

    def do_POST(self):
        if urlparse(self.path).path != "/api/chat":
            return self.send_error(404)
        if not allowed(self.headers.get("Origin")):
            return self.json_response(403, {"error": "origin_not_allowed"})
        if self._rate_limited():
            return self.json_response(429, {"answer": "Слишком много сообщений. Повторите запрос позднее."})
        try:
            length = int(self.headers.get("Content-Length", "0"))
            if length <= 0 or length > 20000:
                raise ValueError
            payload = json.loads(self.rfile.read(length))
            question = str(payload.get("message", "")).strip()
            history = payload.get("history", [])
            if not question or len(question) > 1500 or not isinstance(history, list):
                raise ValueError
        except (ValueError, TypeError, json.JSONDecodeError):
            return self.json_response(400, {"error": "invalid_request", "answer": "Не удалось прочитать вопрос."})

        price = KB.price_answer(question)
        if price:
            return self.json_response(200, {"answer": price, "sources": ["Демонстрационный каталог"]})
        cached = KB.get_cached(question) if not history else None
        if cached:
            return self.json_response(200, {"answer": cached, "cached": True})
        context, hits = KB.context(question)
        if not context:
            return self.json_response(200, {"answer": config.FALLBACK, "sources": []})
        try:
            result = answer(question, context, history)
        except OpenAIError as exc:
            print(f"Text generation failed: {exc}", flush=True)
            result = f"В демонстрационной базе найден материал: {hits[0].content}"
        if not history:
            KB.put_cached(question, result)
        sources = list(dict.fromkeys(source for hit in hits[:3] for source in hit.source.split(" | ")))
        return self.json_response(200, {"answer": result, "sources": sources[:4]})


def main():
    server = ThreadingHTTPServer((config.HOST, config.PORT), Handler)
    print(f"Knowledge assistant demo listening on {config.HOST}:{config.PORT}")
    server.serve_forever()


if __name__ == "__main__":
    main()

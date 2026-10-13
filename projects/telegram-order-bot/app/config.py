from __future__ import annotations

import os
from dataclasses import dataclass

from dotenv import load_dotenv

load_dotenv()


def _to_bool(value: str | None, default: bool = False) -> bool:
    if value is None:
        return default
    return value.strip().lower() in {"1", "true", "yes", "y", "on"}


@dataclass(slots=True)
class Settings:
    bot_token: str
    db_host: str
    db_port: int
    db_name: str
    db_user: str
    db_pass: str
    admin_emails: list[str]
    smtp_host: str
    smtp_port: int
    smtp_user: str
    smtp_pass: str
    smtp_from_email: str
    smtp_use_tls: bool
    smtp_use_ssl: bool
    telegram_proxy: str | None
    bot_mode: str
    webhook_base_url: str | None
    webhook_path: str
    webhook_secret: str | None
    web_server_host: str
    web_server_port: int
    log_level: str

    @property
    def webhook_url(self) -> str | None:
        if not self.webhook_base_url:
            return None
        return self.webhook_base_url.rstrip("/") + self.webhook_path


def load_settings() -> Settings:
    admin_emails = [
        item.strip()
        for item in os.getenv("ADMIN_EMAILS", "admin@example.com").split(",")
        if item.strip()
    ]
    return Settings(
        bot_token=os.getenv("BOT_TOKEN", "replace_me"),
        db_host=os.getenv("DB_HOST", "127.0.0.1"),
        db_port=int(os.getenv("DB_PORT", "3306")),
        db_name=os.getenv("DB_NAME", "demo_orders"),
        db_user=os.getenv("DB_USER", "demo_user"),
        db_pass=os.getenv("DB_PASS", ""),
        admin_emails=admin_emails,
        smtp_host=os.getenv("SMTP_HOST", ""),
        smtp_port=int(os.getenv("SMTP_PORT", "465")),
        smtp_user=os.getenv("SMTP_USER", ""),
        smtp_pass=os.getenv("SMTP_PASS", ""),
        smtp_from_email=os.getenv("SMTP_FROM_EMAIL", "bot@example.com"),
        smtp_use_tls=_to_bool(os.getenv("SMTP_USE_TLS"), False),
        smtp_use_ssl=_to_bool(os.getenv("SMTP_USE_SSL"), True),
        telegram_proxy=os.getenv("TELEGRAM_PROXY") or None,
        bot_mode=os.getenv("BOT_MODE", "polling").strip().lower(),
        webhook_base_url=os.getenv("WEBHOOK_BASE_URL") or None,
        webhook_path=os.getenv("WEBHOOK_PATH", "/telegram/webhook"),
        webhook_secret=os.getenv("WEBHOOK_SECRET") or None,
        web_server_host=os.getenv("WEB_SERVER_HOST", "127.0.0.1"),
        web_server_port=int(os.getenv("WEB_SERVER_PORT", "8080")),
        log_level=os.getenv("LOG_LEVEL", "INFO"),
    )

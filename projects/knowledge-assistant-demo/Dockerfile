FROM python:3.12-slim
WORKDIR /srv/knowledge-assistant
COPY . .
RUN useradd --system --uid 10001 assistant && mkdir -p /srv/knowledge-assistant/data && chown -R assistant:assistant /srv/knowledge-assistant
USER assistant
EXPOSE 8080
CMD ["python", "-m", "app.server"]

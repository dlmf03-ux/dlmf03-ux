from __future__ import annotations

import asyncio
import logging

from aiohttp import web
from aiogram import Bot, Dispatcher
from aiogram.client.session.aiohttp import AiohttpSession
from aiogram.types import Update
from aiogram.webhook.aiohttp_server import SimpleRequestHandler, setup_application

from app.bot_logic import BotLogic
from app.config import load_settings
from app.handlers import create_router
from app.repositories.bot_state_repo import BotStateRepository
from app.repositories.catalog_repo import CatalogRepository
from app.repositories.database import Database
from app.repositories.order_repo import OrderRepository
from app.repositories.user_repo import UserRepository
from app.services.email_service import EmailService


def setup_logging(level: str) -> None:
    logging.basicConfig(
        level=getattr(logging, level.upper(), logging.INFO),
        format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    )


async def build_dispatcher():
    settings = load_settings()
    setup_logging(settings.log_level)

    session = AiohttpSession(proxy=settings.telegram_proxy) if settings.telegram_proxy else AiohttpSession()
    bot = Bot(token=settings.bot_token, session=session)
    dp = Dispatcher()

    db = Database(settings)
    await db.connect()
    await db.ensure_runtime_tables()

    bot_logic = BotLogic(
        state_repo=BotStateRepository(db),
        user_repo=UserRepository(db),
        catalog_repo=CatalogRepository(db),
        order_repo=OrderRepository(db),
        email_service=EmailService(settings),
    )
    dp.include_router(create_router(bot_logic))

    return settings, bot, dp, db


async def run_polling() -> None:
    settings, bot, dp, db = await build_dispatcher()
    try:
        await bot.delete_webhook(drop_pending_updates=False)
        await dp.start_polling(bot, allowed_updates=dp.resolve_used_update_types())
    finally:
        await db.close()
        await bot.session.close()


async def run_webhook() -> None:
    settings, bot, dp, db = await build_dispatcher()
    if not settings.webhook_url:
        raise RuntimeError("WEBHOOK_BASE_URL is required for webhook mode")

    app = web.Application()
    handler = SimpleRequestHandler(
        dispatcher=dp,
        bot=bot,
        secret_token=settings.webhook_secret,
    )
    handler.register(app, path=settings.webhook_path)
    setup_application(app, dp, bot=bot)

    async def on_startup(app: web.Application) -> None:
        await bot.set_webhook(
            url=settings.webhook_url,
            secret_token=settings.webhook_secret,
            allowed_updates=dp.resolve_used_update_types(),
            drop_pending_updates=False,
        )

    async def on_shutdown(app: web.Application) -> None:
        await bot.session.close()
        await db.close()

    app.on_startup.append(on_startup)
    app.on_shutdown.append(on_shutdown)
    web.run_app(app, host=settings.web_server_host, port=settings.web_server_port)


def main() -> None:
    settings = load_settings()
    if settings.bot_mode == "webhook":
        asyncio.run(run_webhook())
    else:
        asyncio.run(run_polling())


if __name__ == "__main__":
    main()

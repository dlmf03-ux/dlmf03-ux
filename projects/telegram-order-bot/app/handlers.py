from __future__ import annotations

from aiogram import F, Router
from aiogram.types import CallbackQuery, Message

from app.bot_logic import BotLogic


def create_router(bot_logic: BotLogic) -> Router:
    router = Router()

    @router.message()
    async def on_message(message: Message) -> None:
        await bot_logic.process_message(message.bot, message)

    @router.callback_query()
    async def on_callback(callback: CallbackQuery) -> None:
        await bot_logic.process_callback(callback.bot, callback)

    return router

from __future__ import annotations

from decimal import Decimal

from app.models import BotState
from app.repositories.database import Database
from app.utils.serialization import dumps, loads


class BotStateRepository:
    def __init__(self, db: Database) -> None:
        self.db = db

    @staticmethod
    def _state_from_json(payload: dict) -> BotState:
        state = BotState()
        for key, value in payload.items():
            if hasattr(state, key):
                setattr(state, key, value)
        if state.promo_discount is not None and not isinstance(state.promo_discount, Decimal):
            state.promo_discount = Decimal(str(state.promo_discount))
        return state

    async def get(self, telegram_id: int) -> BotState:
        assert self.db.pool is not None
        query = "SELECT state_json FROM bot_user_state WHERE telegram_id=%s"
        async with self.db.pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(query, (telegram_id,))
                row = await cur.fetchone()
        if not row:
            return BotState()
        payload = loads(row[0], {})
        return self._state_from_json(payload)

    async def save(self, telegram_id: int, state: BotState) -> None:
        assert self.db.pool is not None
        query = '''
        INSERT INTO bot_user_state (telegram_id, state_json)
        VALUES (%s, %s)
        ON DUPLICATE KEY UPDATE state_json=VALUES(state_json)
        '''
        async with self.db.pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(query, (telegram_id, dumps(state)))
            await conn.commit()

    async def clear(self, telegram_id: int) -> None:
        await self.save(telegram_id, BotState())

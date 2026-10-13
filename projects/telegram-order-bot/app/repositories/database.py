from __future__ import annotations

import aiomysql

from app.config import Settings


class Database:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings
        self.pool: aiomysql.Pool | None = None

    async def connect(self) -> None:
        self.pool = await aiomysql.create_pool(
            host=self.settings.db_host,
            port=self.settings.db_port,
            user=self.settings.db_user,
            password=self.settings.db_pass,
            db=self.settings.db_name,
            minsize=1,
            maxsize=10,
            autocommit=False,
            charset="utf8mb4",
        )

    async def close(self) -> None:
        if self.pool is not None:
            self.pool.close()
            await self.pool.wait_closed()

    async def ensure_runtime_tables(self) -> None:
        assert self.pool is not None
        create_sql = '''
        CREATE TABLE IF NOT EXISTS bot_user_state (
            telegram_id BIGINT NOT NULL PRIMARY KEY,
            state_json JSON NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        '''
        async with self.pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(create_sql)
            await conn.commit()

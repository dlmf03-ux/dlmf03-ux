from __future__ import annotations

import aiomysql

from app.models import UserProfile
from app.repositories.database import Database


class UserRepository:
    def __init__(self, db: Database) -> None:
        self.db = db

    async def get_user(self, telegram_id: int | None, username: str | None) -> UserProfile | None:
        assert self.db.pool is not None
        query = '''
        SELECT telegram_id, username, first_name, last_name, name, phone, email, region, address
        FROM users
        WHERE telegram_id = %s OR username = %s
        LIMIT 1
        '''
        async with self.db.pool.acquire() as conn:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute(query, (telegram_id, username))
                row = await cur.fetchone()
        if not row:
            return None
        return UserProfile(**row)

    async def save_user(
        self,
        telegram_id: int | None,
        username: str | None,
        first_name: str | None,
        last_name: str | None,
        contacts: dict,
        region: str | None = None,
        address: str | None = None,
    ) -> None:
        assert self.db.pool is not None
        query = '''
        INSERT INTO users (
            telegram_id, username, first_name, last_name, name, phone, email, region, address, created_at, updated_at
        )
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            username = VALUES(username),
            first_name = VALUES(first_name),
            last_name = VALUES(last_name),
            name = VALUES(name),
            phone = VALUES(phone),
            email = VALUES(email),
            region = COALESCE(VALUES(region), region),
            address = COALESCE(VALUES(address), address),
            updated_at = NOW()
        '''
        params = (
            telegram_id,
            username,
            first_name,
            last_name,
            contacts.get("name"),
            contacts.get("phone"),
            contacts.get("email"),
            region,
            address,
        )
        async with self.db.pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(query, params)
            await conn.commit()

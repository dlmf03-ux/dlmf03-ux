from __future__ import annotations

import logging
from decimal import Decimal

from aiogram import Bot
from aiogram.types import CallbackQuery, Message, InlineKeyboardMarkup

from app.keyboards import (
    confirm_large_quantities_keyboard,
    contacts_keyboard,
    more_items_keyboard,
    new_order_keyboard,
    payment_keyboard,
    promo_keyboard,
    social_keyboard,
    yes_no_keyboard,
    with_optional_reset,
)
from app.models import BotState, ParsedArticle
from app.repositories.bot_state_repo import BotStateRepository
from app.repositories.catalog_repo import CatalogRepository
from app.repositories.order_repo import OrderRepository
from app.repositories.user_repo import UserRepository
from app.services.email_service import EmailService
from app.services.order_service import (
    build_order_items,
    build_order_response,
    check_large_quantities,
    generate_article_variants,
    get_payment_method_name,
    parse_articles_input,
)

logger = logging.getLogger(__name__)


CONTACTS_TEXT = (
    "📌 Наши контакты:\n\n"
    "Phone: +1-555-0100\n"
    "Email: contact@example.com\n"
    "Website: https://example.com/\n\n"
    "Address: 100 Demo Street, Sample City"
)

START_STICKER_ID = ""
YES_STICKER_ID = ""
ORDER_DONE_STICKER_ID = ""
ENABLE_STICKERS = False


class BotLogic:
    def __init__(
        self,
        state_repo: BotStateRepository,
        user_repo: UserRepository,
        catalog_repo: CatalogRepository,
        order_repo: OrderRepository,
        email_service: EmailService,
    ) -> None:
        self.state_repo = state_repo
        self.user_repo = user_repo
        self.catalog_repo = catalog_repo
        self.order_repo = order_repo
        self.email_service = email_service

    async def _safe_send_sticker(self, bot: Bot, chat_id: int, sticker_id: str) -> None:
        if not ENABLE_STICKERS:
            return
        try:
            await bot.send_sticker(chat_id=chat_id, sticker=sticker_id)
        except Exception:
            logger.exception("Sticker send failed")

    async def _answer(self, target: Message, text: str, reply_markup: InlineKeyboardMarkup | None = None) -> None:
        markup = with_optional_reset(text, reply_markup)
        await target.answer(text, reply_markup=markup)

    async def _ensure_known_user(self, telegram_id: int, username: str | None, first_name: str | None, last_name: str | None, state: BotState) -> None:
        if state.user_checked:
            return
        profile = await self.user_repo.get_user(telegram_id, username)
        state.user_checked = True
        state.user_exists = profile is not None
        if profile:
            state.contacts = {
                "name": profile.name or "",
                "phone": profile.phone or "",
                "email": profile.email or "",
            }
            state.region = profile.region
            state.address = profile.address

    async def process_message(self, bot: Bot, message: Message) -> None:
        if not message.from_user or not message.chat:
            return
        telegram_id = message.from_user.id
        state = await self.state_repo.get(telegram_id)
        await self._ensure_known_user(
            telegram_id=telegram_id,
            username=message.from_user.username,
            first_name=message.from_user.first_name,
            last_name=message.from_user.last_name,
            state=state,
        )
        text = (message.text or "").strip()
        if not text:
            await self._answer(message, "⚠️ Поддерживаются только текстовые сообщения.")
            return

        if text == "/start":
            state.state = "start"

        if state.state == "start":
            await self._safe_send_sticker(bot, message.chat.id, START_STICKER_ID)
            await self._answer(message, "Хотите сделать заказ? 🗯", reply_markup=yes_no_keyboard())
        elif state.state == "enter_promo":
            await self._handle_enter_promo(message, state, text)
        elif state.state == "ask_name":
            if len(text.split()) >= 2:
                state.temp_contacts["name"] = text
                state.state = "ask_phone"
                await self._answer(message, "📱 Теперь введите ваш телефон (например: +79161234567):")
            else:
                await self._answer(message, "⚠️ Пожалуйста, введите имя и фамилию (минимум 2 слова")
        elif state.state == "ask_phone":
            phone = "".join(ch for ch in text if ch.isdigit())
            if len(phone) >= 5:
                state.temp_contacts["phone"] = text
                state.state = "ask_email"
                await self._answer(message, "📧 Теперь введите ваш email (например: ivanov@example.com):")
            else:
                await self._answer(message, "⚠️ Некорректный телефон. Введите номер снова (минимум 5 цифр):")
        elif state.state == "ask_email":
            await self._handle_ask_email(message, state, text)
        elif state.state == "ask_region":
            state.region = text
            state.state = "ask_payment"
            await self._answer(message, "💳 Выберите способ оплаты:", reply_markup=payment_keyboard())
        elif state.state == "ask_address":
            state.address = text
            state.state = "ask_articles"
            await message.answer(
                "📦 Введите артикулы и количество через запятую в формате:\n\n"
                "Артикул Количество, Артикул Количество\n\nили:\n"
                "Артикул Количество\nАртикул Количество\n\nНапример:\n10001 5, 10002 9\nили:\n10001 5\n10002 9"
            )
        elif state.state == "ask_articles":
            await self._handle_articles_input(message, state, text)
        elif state.state == "ask_payment":
            await self._answer(message, "💳 Выберите способ оплаты:", reply_markup=payment_keyboard())
        else:
            state.state = "start"
            await self._answer(message, "Хотите сделать заказ? 🗯", reply_markup=yes_no_keyboard())

        await self.state_repo.save(telegram_id, state)

    async def process_callback(self, bot: Bot, callback: CallbackQuery) -> None:
        if not callback.from_user or not callback.message:
            return
        telegram_id = callback.from_user.id
        state = await self.state_repo.get(telegram_id)
        await self._ensure_known_user(
            telegram_id=telegram_id,
            username=callback.from_user.username,
            first_name=callback.from_user.first_name,
            last_name=callback.from_user.last_name,
            state=state,
        )
        data = callback.data or ""
        await callback.answer()

        if data == "reset_order":
            state = BotState(user_checked=state.user_checked, user_exists=state.user_exists, contacts=state.contacts)
            state.state = "start"
            await self._answer(callback.message, "Заказ сброшен.")
        elif data == "yes":
            if state.user_exists:
                state.state = "ask_promo"
                await self._safe_send_sticker(bot, callback.message.chat.id, YES_STICKER_ID)
                await self._answer(callback.message, "У вас есть промокод?", reply_markup=promo_keyboard())
            else:
                state.state = "ask_social"
                await self._safe_send_sticker(bot, callback.message.chat.id, YES_STICKER_ID)
                await self._answer(callback.message, "Узнали о нас из интернета (соцсетей)?", reply_markup=social_keyboard())
        elif data in {"social_yes", "social_no"}:
            state.social_source = data == "social_yes"
            state.state = "ask_promo"
            await self._answer(callback.message, "У вас есть промокод?", reply_markup=promo_keyboard())
        elif data == "promo_yes":
            state.state = "enter_promo"
            await self._answer(callback.message, "Введите ваш промокод:")
        elif data == "promo_no":
            state.promo_used = False
            state.promo_code = None
            state.promo_discount = None
            if state.user_exists:
                state.state = "ask_payment"
                await self._answer(callback.message, "💳 Выберите способ оплаты:", reply_markup=payment_keyboard())
            else:
                state.state = "ask_name"
                await self._answer(callback.message, "📛 Пожалуйста, введите ваше name / organization:")
        elif data in {"cash", "card", "bank_transfer", "sberbank"}:
            state.payment_method = data
            state.state = "ask_address"
            await self._answer(callback.message, "🏠 Введите адрес доставки:")
        elif data == "no":
            state.state = "after_no"
            await self._answer(callback.message, "😢 Очень жаль! Ждем вас снова!", reply_markup=contacts_keyboard())
        elif data == "contacts":
            state.state = "show_contacts"
            await self._answer(callback.message, CONTACTS_TEXT, reply_markup=contacts_keyboard())
        elif data == "new_order":
            preserved_contacts = state.contacts
            state = BotState(user_checked=True, user_exists=bool(preserved_contacts), contacts=preserved_contacts)
            state.state = "start"
            await self._answer(callback.message, "👋 Привет! Хотите сделать новый заказ?", reply_markup=yes_no_keyboard())
        elif data == "more_yes":
            state.state = "ask_articles"
            await callback.message.answer(
                "📦 Введите артикулы и количество через запятую или с новой строки в формате:\n\n"
                "Артикул Количество, Артикул Количество\n\nили:\n"
                "Артикул Количество\nАртикул Количество\n\nНапример:\n10001 5, 10002 9\nили:\n10001 5\n10002 9"
            )
        elif data == "more_no":
            await self._handle_more_no(callback.message, state, callback.from_user.id, callback.from_user.username)
        elif data == "more_reset":
            state = BotState(user_checked=state.user_checked, user_exists=state.user_exists, contacts=state.contacts)
            state.state = "start"
            await self._answer(callback.message, "Заказ сброшен. Хотите начать заново?", reply_markup=yes_no_keyboard())
        elif data == "confirm_large_quantities":
            await self._finalize_order(callback.message, state, callback.from_user.id, callback.from_user.username)
        elif data == "edit_large_quantities":
            state.state = "ask_articles"
            state.large_quantity_items = []
            current_order = "\n".join(
                f"{item['original']} {item['quantity']}" for item in state.order_items
            )
            await callback.message.answer(
                "📦 Измените количество товаров в формате:\n\n"
                "Артикул Количество, Артикул Количество\n\nили:\n"
                "Артикул Количество\nАртикул Количество\n\n"
                f"Текущий заказ:\n{current_order}"
            )
        elif data == "back_to_name":
            state.state = "ask_name"
            await self._answer(callback.message, "📛 Введите ваше имя / организацию:")
        elif data == "back_to_phone":
            state.state = "ask_phone"
            await self._answer(callback.message, "📱 Введите ваш телефон:")

        await self.state_repo.save(telegram_id, state)

    async def _handle_enter_promo(self, message: Message, state: BotState, text: str) -> None:
        promo_codes = await self.catalog_repo.load_promo_codes()
        matched = None
        for code, promo in promo_codes.items():
            if text.lower() == code.lower():
                matched = promo
                state.promo_used = promo.discount != Decimal("1.00")
                state.promo_code = promo.code
                state.promo_discount = promo.discount
                break
        if matched:
            if matched.message:
                await self._answer(message, f"✅ Промокод '{matched.code}' принят!\n{matched.message}")
            else:
                await self._answer(message, f"✅ Промокод '{matched.code}' применен! Скидка активирована.")
        else:
            state.promo_used = False
            state.promo_code = None
            state.promo_discount = None
            await self._answer(message, "⚠️ Промокод недействителен")

        if state.user_exists:
            state.state = "ask_payment"
            await self._answer(message, "💳 Выберите способ оплаты:", reply_markup=payment_keyboard())
        else:
            state.state = "ask_name"
            await self._answer(message, "📛 Пожалуйста, введите ваше имя / организацию:")

    async def _handle_ask_email(self, message: Message, state: BotState, text: str) -> None:
        if "@" not in text or "." not in text.split("@")[-1]:
            await self._answer(message, "⚠️ Некорректный email. Введите снова (например: ivanov@example.com):")
            return

        if not state.contacts:
            state.contacts = {}
        if not state.user_exists and state.temp_contacts:
            state.contacts["name"] = state.temp_contacts.get("name", "")
            state.contacts["phone"] = state.temp_contacts.get("phone", "")

        state.contacts["name"] = state.contacts.get("name") or "Не указано"
        state.contacts["phone"] = state.contacts.get("phone") or "+70000000000"
        state.contacts["email"] = text
        state.temp_contacts = {}

        await self.user_repo.save_user(
            telegram_id=message.from_user.id if message.from_user else None,
            username=message.from_user.username if message.from_user else None,
            first_name=message.from_user.first_name if message.from_user else None,
            last_name=message.from_user.last_name if message.from_user else None,
            contacts=state.contacts,
            region=state.region,
            address=state.address,
        )

        if state.user_exists:
            state.state = "ask_payment"
            await self._answer(message, "💳 Выберите способ оплаты:", reply_markup=payment_keyboard())
        else:
            state.state = "ask_region"
            await self._answer(message, "🌍 Введите ваш регион:")

    async def _handle_articles_input(self, message: Message, state: BotState, text: str) -> None:
        parsed, errors = parse_articles_input(text)
        if errors:
            await self._answer(
                message,
                "⚠️ Неверный формат для: " + ", ".join(errors) +
                "\n\nПример: 2802 5, 0023 6\nили:\n2802 5\n0023 6"
            )
            return
        if not parsed:
            await message.answer(
                "⚠️ Неверный формат. Пожалуйста, введите данные как в примере:\n123060 2, 116301 1\nили:\n123060 2\n116301 1"
            )
            return

        state.large_quantity_items = []
        state.order_items = [
            {"original": item.original, "variants": item.variants, "quantity": item.quantity}
            for item in parsed
        ]
        large = check_large_quantities(parsed)
        if large:
            state.large_quantity_items = large
            state.state = "confirm_large_quantities"
            msg = "⚠️ Вы заказываете большое количество следующих товаров:\n\n"
            for item in large:
                msg += f"• {item['original']} - {item['quantity']} шт.\n"
            msg += "\nВы уверены, что необходимо такое количество?"
            await self._answer(message, msg, reply_markup=confirm_large_quantities_keyboard())
        else:
            state.state = "ask_more_items"
            await self._answer(message, "Может, что-то еще нужно?", reply_markup=more_items_keyboard())

    async def _handle_more_no(self, message: Message, state: BotState, telegram_id: int, username: str | None) -> None:
        if not state.order_items:
            await self._answer(message, "⚠️ В заказе нет товаров. Пожалуйста, добавьте товары.")
            state.state = "ask_articles"
            await message.answer(
                "📦 Введите артикулы и количество через запятую или с новой строки в формате:\n\n"
                "Артикул Количество, Артикул Количество\n\nили:\n"
                "Артикул Количество\nАртикул Количество"
            )
            return
        parsed = [
            ParsedArticle(
                original=item["original"],
                variants=[str(v) for v in item["variants"]],
                quantity=int(item["quantity"]),
            )
            for item in state.order_items
        ]
        large = check_large_quantities(parsed)
        if large:
            state.large_quantity_items = large
            state.state = "confirm_large_quantities"
            msg = "⚠️ Вы заказываете большое количество следующих товаров:\n\n"
            for item in large:
                msg += f"• {item['original']} - {item['quantity']} шт.\n"
            msg += "\nВы уверены, что необходимо такое количество?"
            await self._answer(message, msg, reply_markup=confirm_large_quantities_keyboard())
            return

        await self._finalize_order(message, state, telegram_id, username)

    async def _finalize_order(self, message: Message, state: BotState, telegram_id: int, username: str | None) -> None:
        try:
            parsed = [
                ParsedArticle(
                    original=item["original"],
                    variants=[str(v) for v in item["variants"]],
                    quantity=int(item["quantity"]),
                )
                for item in state.order_items
            ]
            search_articles: list[str] = []
            for item in parsed:
                search_articles.extend(item.variants)
            search_articles = list(dict.fromkeys(search_articles))

            products = await self.catalog_repo.find_products_by_articles(search_articles)
            excluded_articles = await self.catalog_repo.load_promo_exclusions()
            order_items, not_found, total, discounted_total, has_exclusions, discount_applied = build_order_items(
                parsed_items=parsed,
                products=products,
                excluded_articles=excluded_articles,
                promo_used=state.promo_used,
                promo_code=state.promo_code,
            )

            if not_found:
                await self._answer(message, "⚠️ Не найдены товары: " + ", ".join(not_found))
            if not order_items:
                await self._answer(message, "⚠️ Ни один товар не найден в базе")
                state.state = "ask_articles"
                return

            response = build_order_response(
                items=order_items,
                discounted_total=discounted_total,
                total=total,
                discount_applied=discount_applied,
                has_exclusions=has_exclusions,
                promo_used=state.promo_used,
                promo_code=state.promo_code,
            )

            if state.contacts:
                await self.email_service.send_order_email(
                    contacts=state.contacts,
                    region=state.region,
                    payment_method=state.payment_method or "",
                    address=state.address or "",
                    promo_code=state.promo_code,
                    discount_applied=discount_applied,
                    has_exclusions=has_exclusions,
                    total_amount=discounted_total,
                    original_amount=total,
                    items=order_items,
                )

            await self.order_repo.save_order(
                contacts=state.contacts,
                region=state.region,
                payment_method=get_payment_method_name(state.payment_method or ""),
                address=state.address or "",
                social_source=state.social_source,
                promo_used=discount_applied and not has_exclusions,
                promo_code=state.promo_code,
                total_amount=discounted_total,
                original_amount=total,
                telegram_id=telegram_id,
                username=username,
                has_exclusions=has_exclusions,
                items=order_items,
            )

            await self._safe_send_sticker(message.bot, message.chat.id, ORDER_DONE_STICKER_ID)
            await self._answer(message, response, reply_markup=new_order_keyboard())

        except Exception:
            logger.exception("Order processing failed")
            await self._answer(message, "⛔ Произошла ошибка при обработке заказа. Пожалуйста, попробуйте позже.")
        finally:
            preserved_contacts = state.contacts.copy()
            preserved_flags = (state.user_checked, state.user_exists)
            state.reset_for_new_order()
            state.contacts = preserved_contacts
            state.user_checked, state.user_exists = preserved_flags





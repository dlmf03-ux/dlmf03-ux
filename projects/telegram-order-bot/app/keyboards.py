from __future__ import annotations

from aiogram.types import InlineKeyboardMarkup
from aiogram.utils.keyboard import InlineKeyboardBuilder


RESET_EXCLUDED_TEXTS = {
    "Хотите сделать заказ?",
    "✅ Заказ оформлен!",
    "😢 Очень жаль! Ждем вас снова!",
    "Заказ сброшен.",
    "Узнали о нас из интернета (соцсетей)?",
    "Может, что-то еще нужно?",
    "Введите ваш промокод:",
}


def yes_no_keyboard() -> InlineKeyboardMarkup:
    kb = InlineKeyboardBuilder()
    kb.button(text="✅ Да", callback_data="yes")
    kb.button(text="❌ Нет", callback_data="no")
    kb.adjust(2)
    return kb.as_markup()



def social_keyboard() -> InlineKeyboardMarkup:
    kb = InlineKeyboardBuilder()
    kb.button(text="✅ Да", callback_data="social_yes")
    kb.button(text="❌ Нет", callback_data="social_no")
    kb.adjust(2)
    return kb.as_markup()



def promo_keyboard() -> InlineKeyboardMarkup:
    kb = InlineKeyboardBuilder()
    kb.button(text="✅ Да", callback_data="promo_yes")
    kb.button(text="❌ Нет", callback_data="promo_no")
    kb.adjust(2)
    return kb.as_markup()



def payment_keyboard() -> InlineKeyboardMarkup:
    kb = InlineKeyboardBuilder()
    kb.button(text="Нал", callback_data="cash")
    kb.button(text="Карта", callback_data="card")
    kb.button(text="Безнал", callback_data="bank_transfer")
    kb.button(text="Сбер", callback_data="sberbank")
    kb.adjust(2, 2)
    return kb.as_markup()



def contacts_keyboard() -> InlineKeyboardMarkup:
    kb = InlineKeyboardBuilder()
    kb.button(text="📞 Контакты", callback_data="contacts")
    kb.button(text="🛍 Сделать заказ", callback_data="yes")
    kb.adjust(2)
    return kb.as_markup()



def more_items_keyboard() -> InlineKeyboardMarkup:
    kb = InlineKeyboardBuilder()
    kb.button(text="✅ Да", callback_data="more_yes")
    kb.button(text="❌ Нет", callback_data="more_no")
    kb.button(text="🔄 Сбросить заказ", callback_data="more_reset")
    kb.adjust(2, 1)
    return kb.as_markup()



def confirm_large_quantities_keyboard() -> InlineKeyboardMarkup:
    kb = InlineKeyboardBuilder()
    kb.button(text="✅ Да, подтверждаю", callback_data="confirm_large_quantities")
    kb.button(text="❌ Нет, изменить количество", callback_data="edit_large_quantities")
    kb.adjust(1, 1)
    return kb.as_markup()



def new_order_keyboard() -> InlineKeyboardMarkup:
    kb = InlineKeyboardBuilder()
    kb.button(text="🔄 Сделать новый заказ", callback_data="new_order")
    kb.button(text="📞 Контакты", callback_data="contacts")
    kb.adjust(1, 1)
    return kb.as_markup()



def with_optional_reset(text: str, markup: InlineKeyboardMarkup | None = None) -> InlineKeyboardMarkup | None:
    if text in RESET_EXCLUDED_TEXTS:
        return markup

    kb = InlineKeyboardBuilder()
    if markup and markup.inline_keyboard:
        for row in markup.inline_keyboard:
            for button in row:
                kb.add(button)
        row_widths = [len(row) for row in markup.inline_keyboard]
        if row_widths:
            kb.adjust(*row_widths)

    kb.button(text="🔄 Сбросить заказ", callback_data="reset_order")
    return kb.as_markup()

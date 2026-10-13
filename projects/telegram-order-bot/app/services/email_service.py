from __future__ import annotations

from email.message import EmailMessage
from html import escape

import aiosmtplib

from app.config import Settings
from app.models import OrderItem


PAYMENT_NAMES = {
    "cash": "Наличные",
    "card": "Карта",
    "bank_transfer": "Безналичный расчет",
    "sberbank": "Сбербанк",
}


class EmailService:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings

    async def send_order_email(
        self,
        contacts: dict,
        region: str | None,
        payment_method: str,
        address: str,
        promo_code: str | None,
        discount_applied: bool,
        has_exclusions: bool,
        total_amount,
        original_amount,
        items: list[OrderItem],
    ) -> None:
        if not self.settings.smtp_host or not self.settings.admin_emails:
            return

        msg = EmailMessage()
        msg["From"] = self.settings.smtp_from_email
        msg["To"] = ", ".join(self.settings.admin_emails)
        if contacts.get("email"):
            msg["Reply-To"] = contacts["email"]
        msg["Subject"] = f"Новый заказ от {contacts.get('name', 'Без имени')}"

        payment_name = PAYMENT_NAMES.get(payment_method, payment_method)
        body = [
            "<h2>Новый заказ из Telegram-бота</h2>",
            "<h3>Контактные данные:</h3>",
            f"<p><strong>ФИО:</strong> {escape(str(contacts.get('name', '')))}</p>",
            f"<p><strong>Телефон:</strong> {escape(str(contacts.get('phone', '')))}</p>",
            f"<p><strong>Email:</strong> {escape(str(contacts.get('email', '')))}</p>",
        ]
        if region:
            body.append(f"<p><strong>Регион:</strong> {escape(region)}</p>")
        body.append(f"<p><strong>Способ оплаты:</strong> {escape(payment_name)}</p>")
        body.append(f"<p><strong>Адрес доставки:</strong> {escape(address)}</p>")

        if promo_code:
            status = "не применен"
            if discount_applied:
                status = "частично применен" if has_exclusions else "применен"
            body.append(f"<p><strong>Промокод:</strong> {escape(promo_code)} ({status})</p>")

        body.append(
            "<h3>Состав заказа:</h3>"
            "<table style='border-collapse: collapse; width: 100%;'>"
            "<tr style='background-color: #f2f2f2;'>"
            "<th style='border: 1px solid #ddd; padding: 8px;'>Артикул</th>"
            "<th style='border: 1px solid #ddd; padding: 8px;'>Наименование</th>"
            "<th style='border: 1px solid #ddd; padding: 8px;'>Цена</th>"
            "<th style='border: 1px solid #ddd; padding: 8px;'>Кол-во</th>"
            "<th style='border: 1px solid #ddd; padding: 8px;'>Сумма</th>"
            "</tr>"
        )

        for item in items:
            note = " (товар по акции)" if item.excluded else ""
            price_to_show = item.price if item.excluded or not discount_applied else (item.discounted_sum / item.quantity)
            sum_to_show = item.sum if item.excluded or not discount_applied else item.discounted_sum
            body.append(
                "<tr>"
                f"<td style='border: 1px solid #ddd; padding: 8px;'>{escape(item.article)}</td>"
                f"<td style='border: 1px solid #ddd; padding: 8px;'>{escape(item.name + note)}</td>"
                f"<td style='border: 1px solid #ddd; padding: 8px;'>{price_to_show:.2f} руб.</td>"
                f"<td style='border: 1px solid #ddd; padding: 8px;'>{item.quantity}</td>"
                f"<td style='border: 1px solid #ddd; padding: 8px;'>{sum_to_show:.2f} руб.</td>"
                "</tr>"
            )

        body.append(
            "<tr style='font-weight: bold;'>"
            "<td colspan='4' style='border: 1px solid #ddd; padding: 8px; text-align: right;'>Общая сумма заказа:</td>"
            f"<td style='border: 1px solid #ddd; padding: 8px;'>{total_amount:.2f} руб.</td>"
            "</tr>"
        )
        if discount_applied:
            body.append(
                "<tr style='font-weight: bold;'>"
                "<td colspan='4' style='border: 1px solid #ddd; padding: 8px; text-align: right;'>Сумма без скидки:</td>"
                f"<td style='border: 1px solid #ddd; padding: 8px;'>{original_amount:.2f} руб.</td>"
                "</tr>"
            )
        body.append("</table>")
        if has_exclusions:
            body.append("<p><em>Примечание: для отмеченных товаров не применяются промокоды</em></p>")

        msg.set_content("Новый заказ из Telegram-бота. Откройте HTML-версию письма.")
        msg.add_alternative("".join(body), subtype="html")

        await aiosmtplib.send(
            msg,
            hostname=self.settings.smtp_host,
            port=self.settings.smtp_port,
            username=self.settings.smtp_user or None,
            password=self.settings.smtp_pass or None,
            start_tls=self.settings.smtp_use_tls,
            use_tls=self.settings.smtp_use_ssl,
        )

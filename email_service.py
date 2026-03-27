import asyncio
import base64
import hashlib
import hmac
import logging
import os
import re
import smtplib
import uuid
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
from email.utils import formatdate, make_msgid
from datetime import datetime
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select

log = logging.getLogger("email_service")

UNSUBSCRIBE_SECRET = os.getenv("JWT_SECRET", "change-me-in-production")
FRONTEND_URL = os.getenv("FRONTEND_URL", "https://mail-hub.pro")


def generate_unsubscribe_token(email: str) -> str:
    email_b64 = base64.urlsafe_b64encode(email.encode()).decode()
    sig = hmac.new(UNSUBSCRIBE_SECRET.encode(), email.encode(), hashlib.sha256).hexdigest()
    return f"{email_b64}.{sig}"


def verify_unsubscribe_token(token: str) -> str | None:
    try:
        email_b64, sig = token.rsplit(".", 1)
        email = base64.urlsafe_b64decode(email_b64).decode()
        expected = hmac.new(UNSUBSCRIBE_SECRET.encode(), email.encode(), hashlib.sha256).hexdigest()
        if hmac.compare_digest(sig, expected):
            return email
    except Exception:
        pass
    return None


def build_unsubscribe_url(email: str) -> str:
    return f"{FRONTEND_URL}/unsubscribe/{generate_unsubscribe_token(email)}"


def _strip_html(html: str) -> str:
    text = re.sub(r"<br\s*/?>", "\n", html)
    text = re.sub(r"</p>", "\n\n", text)
    text = re.sub(r"<[^>]+>", "", text)
    text = re.sub(r"\n{3,}", "\n\n", text).strip()
    return text


def _send_single(
    host: str,
    port: int,
    user: str,
    password: str,
    from_email: str,
    from_name: str,
    to_email: str,
    subject: str,
    body: str,
    unsubscribe_url: str | None = None,
    signature: str | None = None,
) -> tuple[bool, str | None]:
    try:
        domain = from_email.split("@")[1] if "@" in from_email else "localhost"

        if signature:
            sig_html = (
                '<div style="margin-top:24px;padding-top:16px;border-top:1px solid #e5e7eb;">'
                f'{signature}'
                '</div>'
            )
            body = body + sig_html

        if unsubscribe_url:
            unsub_html = (
                '<div style="margin-top:30px;padding-top:15px;border-top:1px solid #e5e7eb;'
                'text-align:center;font-size:12px;color:#9ca3af;">'
                'If you no longer wish to receive these emails, '
                f'<a href="{unsubscribe_url}" style="color:#6366f1;">unsubscribe here</a>.'
                '</div>'
            )
            unsub_plain = f"\n\n---\nTo unsubscribe: {unsubscribe_url}"
            body = body + unsub_html
        else:
            unsub_plain = ""

        msg = MIMEMultipart("alternative")
        msg["From"] = f"{from_name} <{from_email}>" if from_name else from_email
        msg["To"] = to_email
        msg["Subject"] = subject
        msg["Date"] = formatdate(localtime=True)
        msg["Message-ID"] = make_msgid(domain=domain)
        msg["MIME-Version"] = "1.0"
        msg["X-Mailer"] = "MailHub/2.0"

        if unsubscribe_url:
            msg["List-Unsubscribe"] = f"<{unsubscribe_url}>"
            msg["List-Unsubscribe-Post"] = "List-Unsubscribe=One-Click"

        plain_text = _strip_html(body) + unsub_plain
        msg.attach(MIMEText(plain_text, "plain", "utf-8"))
        msg.attach(MIMEText(body, "html", "utf-8"))

        with smtplib.SMTP(host, port, timeout=15) as server:
            server.ehlo(domain)
            server.starttls()
            server.ehlo(domain)
            server.login(user, password)
            server.send_message(msg)
        return True, None
    except Exception as e:
        log.error("SMTP send failed to %s: %s", to_email, e)
        return False, str(e)


async def send_test_email(
    host: str,
    port: int,
    user: str,
    password: str,
    from_email: str,
    from_name: str,
    to_email: str,
) -> tuple[bool, str | None]:
    subject = "Ch-Scraper — SMTP Test"
    body = """
    <div style="font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto; padding: 24px;">
      <h2 style="color: #4f46e5;">SMTP Connection Successful</h2>
      <p style="color: #374151; line-height: 1.6;">
        Your SMTP settings are working correctly. Emails from your Ch-Scraper account
        will be sent through this email address.
      </p>
      <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 20px 0;" />
      <p style="color: #9ca3af; font-size: 12px;">
        This is an automated test from Ch-Scraper.
      </p>
    </div>
    """
    return await asyncio.to_thread(
        _send_single, host, port, user, password, from_email, from_name, to_email, subject, body
    )


async def queue_emails(db: AsyncSession, sent_records: list):
    from models import SentEmail, BatchEmail, Business, Company, UnsubscribedEmail

    if not sent_records:
        return

    company_id = sent_records[0].company_id
    comp_q = await db.execute(select(Company).where(Company.id == company_id))
    company = comp_q.scalar_one_or_none()

    if not company or not company.smtp_enabled or not company.smtp_host or not company.smtp_pass:
        for record in sent_records:
            record.status = "failed"
            record.sent_at = datetime.utcnow()
        await db.commit()
        log.warning("Company %s has no SMTP configured — marking %d emails as failed", company_id, len(sent_records))
        return

    unsub_q = await db.execute(select(UnsubscribedEmail.email))
    unsubscribed_set = {row[0].lower() for row in unsub_q.all()}

    async def process(record):
        be_q = await db.execute(
            select(Business.email)
            .join(BatchEmail, BatchEmail.business_id == Business.id)
            .where(BatchEmail.id == record.batch_email_id)
        )
        recipient = be_q.scalar()
        if not recipient:
            record.status = "failed"
        elif recipient.lower() in unsubscribed_set:
            record.status = "unsubscribed"
            log.info("Skipped unsubscribed email: %s", recipient)
        else:
            unsub_url = build_unsubscribe_url(recipient)
            success, error = await asyncio.to_thread(
                _send_single,
                company.smtp_host,
                company.smtp_port or 587,
                company.smtp_user,
                company.smtp_pass,
                company.smtp_from_email or company.smtp_user,
                company.smtp_from_name or company.name,
                recipient,
                record.subject,
                record.body,
                unsub_url,
                company.email_signature,
            )
            record.status = "sent" if success else "failed"
            if error:
                log.error("Failed to send to %s: %s", recipient, error)
        record.sent_at = datetime.utcnow()

    for record in sent_records:
        await process(record)

    await db.commit()

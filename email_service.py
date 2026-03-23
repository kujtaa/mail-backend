import asyncio
import logging
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
) -> tuple[bool, str | None]:
    try:
        domain = from_email.split("@")[1] if "@" in from_email else "localhost"

        msg = MIMEMultipart("alternative")
        msg["From"] = f"{from_name} <{from_email}>" if from_name else from_email
        msg["To"] = to_email
        msg["Subject"] = subject
        msg["Date"] = formatdate(localtime=True)
        msg["Message-ID"] = make_msgid(domain=domain)
        msg["MIME-Version"] = "1.0"
        msg["X-Mailer"] = "Ch-Scraper/2.0"

        plain_text = _strip_html(body)
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
    from models import SentEmail, BatchEmail, Business, Company

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

    async def process(record):
        be_q = await db.execute(
            select(Business.email)
            .join(BatchEmail, BatchEmail.business_id == Business.id)
            .where(BatchEmail.id == record.batch_email_id)
        )
        recipient = be_q.scalar()
        if not recipient:
            record.status = "failed"
        else:
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
            )
            record.status = "sent" if success else "failed"
            if error:
                log.error("Failed to send to %s: %s", recipient, error)
        record.sent_at = datetime.utcnow()

    for record in sent_records:
        await process(record)

    await db.commit()

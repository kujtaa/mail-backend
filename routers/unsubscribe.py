from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select

from db import get_db
from models import UnsubscribedEmail, Business
from email_service import verify_unsubscribe_token, generate_unsubscribe_token

router = APIRouter(prefix="/unsubscribe", tags=["Unsubscribe"])


@router.get("/{token}")
async def handle_unsubscribe(
    token: str,
    db: AsyncSession = Depends(get_db),
):
    email = verify_unsubscribe_token(token)
    if not email:
        raise HTTPException(status_code=400, detail="Invalid or expired unsubscribe link.")

    existing = await db.execute(
        select(UnsubscribedEmail).where(UnsubscribedEmail.email == email)
    )
    if existing.scalar_one_or_none():
        return {"detail": "This email has already been unsubscribed.", "email": email}

    biz_q = await db.execute(
        select(Business.id).where(Business.email == email).limit(1)
    )
    biz_id = biz_q.scalar()

    unsub = UnsubscribedEmail(
        email=email,
        business_id=biz_id,
        token=token,
    )
    db.add(unsub)
    await db.commit()
    return {"detail": "Successfully unsubscribed.", "email": email}

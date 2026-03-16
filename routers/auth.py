from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, func

from db import get_db
from models import Company
from schemas import RegisterRequest, LoginRequest, TokenResponse, CompanyProfile
from auth import hash_password, verify_password, create_access_token
from dependencies import get_current_company

router = APIRouter(prefix="/auth", tags=["Auth"])


@router.post("/register", response_model=TokenResponse)
async def register(req: RegisterRequest, db: AsyncSession = Depends(get_db)):
    existing = await db.execute(select(Company).where(Company.email == req.email))
    if existing.scalar_one_or_none():
        raise HTTPException(status_code=400, detail="Email already registered")

    count_result = await db.execute(select(func.count(Company.id)))
    is_first = count_result.scalar() == 0

    company = Company(
        name=req.company_name,
        email=req.email,
        hashed_password=hash_password(req.password),
        credit_balance=0.0,
        is_admin=is_first,
        is_approved=is_first,
    )
    db.add(company)
    await db.commit()
    await db.refresh(company)

    token = create_access_token({"sub": str(company.id)})
    return TokenResponse(access_token=token)


@router.post("/login")
async def login(req: LoginRequest, db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(Company).where(Company.email == req.email))
    company = result.scalar_one_or_none()
    if not company or not verify_password(req.password, company.hashed_password):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid credentials")

    token = create_access_token({"sub": str(company.id)})
    return {
        "access_token": token,
        "token_type": "bearer",
        "company": CompanyProfile.model_validate(company),
    }


@router.get("/me", response_model=CompanyProfile)
async def me(company: Company = Depends(get_current_company)):
    return CompanyProfile.model_validate(company)

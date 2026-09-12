# Staging architecture (ENG-040A)

Derived from TAD §§3, 7, 8.1, 9, 39, 67–68, 83, 98 — not invented alternative topology.

## Target topology (staging)

```text
Internet (HTTPS / ACM)
  → CloudFront
      → static Primary FE (S3 origin)     [SPA build artifacts]
      → static Admin FE (S3 origin)       [SPA build artifacts]
      → ALB (API + WebSocket upgrade)
          → ECS/Fargate: api
          → ECS/Fargate: reverb          [separate service; WSS]
          → (ALB does not run workers)
  → ECS/Fargate: worker                  [queue:work redis]
  → ECS/Fargate: scheduler               [schedule:work]
  → RDS MySQL (private)
  → ElastiCache Redis (private)
  → S3 private buckets (evidence + campaign media)
  → Secrets Manager → ECS task secrets
  → CloudWatch logs/metrics
  → ECR images
  → Route 53 DNS
  → Paystack (TEST mode only)
  → Mail provider (SES/SMTP staging domain)
```

### TAD notes

- App hosting: **ECS/Fargate or equivalent** (TAD §9).
- Frontend hosting beyond “CloudFront → ALB → Laravel” is underspecified; staging uses **S3+CloudFront for SPAs** and **ALB→ECS for API/Reverb** so browser UAT can hit HTTPS origins without baking SPAs into the API container.
- Reverb is **not named** in the TAD (generic WebSocket layer). Staging reuses the existing MH-BE-013 Reverb implementation.
- Exact VPC/subnet/SG sizing is left to infrastructure implementation (TAD §9).

## Process separation

| Component | Image target | Command |
| --------- | ------------ | ------- |
| API | `api` | `php artisan serve` (staging bootstrap; may later use php-fpm+nginx) |
| Queue worker | `worker` | `php artisan queue:work redis` |
| Scheduler | `scheduler` | `php artisan schedule:work` |
| Reverb | `reverb` | `php artisan reverb:start` |

Do not combine worker/scheduler/reverb into the API process.

## Environment separation

| Env | Purpose |
| --- | ------- |
| `local` | Engineer machines (`APP_DEBUG` may be true) |
| `staging` | QA/UAT (`APP_DEBUG=false`, test Paystack, staging mail) |
| `production` | Real customers — **not configured by ENG-040A** |

## Secrets

- Never commit real credentials.
- Staging secrets: AWS Secrets Manager (or GitHub Environment secrets until AWS is wired).
- Containers must receive secrets via ECS injection / task role — not baked into images.

# Architecture — ENG-040 / MH-OPS-001

Derived from TAD §§3, 7, 8.1, 9, 34, 39, 67–68, 83, 98 and ADR-006 (AWS). Not an alternate hosting model.

## Target topology

```text
Internet (HTTPS / ACM)
  → Route 53
  → CloudFront
      → S3 origin: Participant SPA
      → S3 origin: Admin SPA
      → ALB origin: Laravel API (+ optional API edge)
  → ALB
      → ECS/Fargate service: api
      → ECS/Fargate service: reverb (WSS / private conversation.{id})
  → ECS/Fargate service: worker   (queue:work redis)
  → ECS/Fargate service: scheduler (schedule:work)
  → RDS MySQL (private subnets)
  → ElastiCache Redis (private)
  → S3 private buckets (sensitive evidence + campaign media)
  → Secrets Manager → ECS task definitions
  → CloudWatch logs + alarms
  → ECR images
  → Paystack (staging = TEST keys only)
  → Mail (SES/SMTP; staging sender identity)
```

### Authoritative AWS services (TAD §9)

| Concern | Service |
| ------- | ------- |
| DNS | Route 53 |
| CDN / HTTPS edge | CloudFront + ACM |
| Load balancing | Application Load Balancer |
| Compute | ECS/Fargate (or equivalent managed containers) |
| Database | Amazon RDS MySQL |
| Cache / queue / sessions | ElastiCache Redis |
| Object storage | Amazon S3 (private) |
| Encryption | AWS KMS |
| Secrets | AWS Secrets Manager |
| Observability | CloudWatch |
| Images | Amazon ECR |
| Backups | RDS automated backups + S3 versioning/lifecycle |
| CI/CD | GitHub Actions |

Exact VPC sizing is finalized during infrastructure implementation (TAD §9).

### Frontend hosting note

TAD diagrams show CloudFront → ALB → Laravel. SPAs are separate Vite builds. Staging/production host **static SPAs on S3+CloudFront** and **API/Reverb on ALB→ECS** so UAT can use HTTPS origins without embedding SPAs in the API image. This does not replace Reverb or invent a non-AWS stack.

### Reverb

TAD names a generic WebSocket layer. MarcatursHub implements **Laravel Reverb** (MH-BE-013) with private `conversation.{id}` authorization. Do not substitute another WebSocket product.

## Process separation

| Component | Dockerfile target | Command |
| --------- | ----------------- | ------- |
| API | `api` | `php artisan serve --host=0.0.0.0 --port=8000` (staging bootstrap; php-fpm+nginx optional later) |
| Queue worker | `worker` | `php artisan queue:work redis --sleep=1 --tries=3 --max-time=3600` |
| Scheduler | `scheduler` | `php artisan schedule:work` |
| Reverb | `reverb` | `php artisan reverb:start --host=0.0.0.0 --port=8080` |

Never combine worker/scheduler/reverb into the API process (duplicate scheduler risk).

## Scheduled jobs (application)

Registered in `bootstrap/app.php`:

- `campaigns:process-lifecycle` — every 15 minutes
- `commissions:process-overdue` — every 15 minutes
- `commissions:process-reminders` — every 15 minutes

## Environment separation

| Env | `APP_ENV` | `APP_DEBUG` | Data / secrets |
| --- | --------- | ----------- | -------------- |
| Local | `local` | may be `true` | Engineer machine only |
| Staging | `staging` | **`false`** | Isolated DB/Redis/S3/Paystack test/mail |
| Production | `production` | **`false`** | Real customers — see `production.md` |

Production data must never be casually copied into development (TAD §8).

## Secrets

- Never commit real credentials.
- Staging/production: AWS Secrets Manager → ECS task secrets.
- Images must not bake secrets.
- `VITE_*` values are public to browsers — never put Paystack secret / DB passwords in frontend env.

# AWS staging checklist (ENG-040)
# Apply only after AWS account access exists. Prefer IaC under infra/aws/staging/.

## Network
- [ ] VPC with public + private subnets
- [ ] NAT for private ECS tasks
- [ ] Security groups: ALB → ECS api/reverb; ECS → RDS/Redis/S3
- [ ] RDS and Redis: private only (no public endpoints)

## Data
- [ ] RDS MySQL 8 (utf8mb4), staging-sized instance
- [ ] Automated backups enabled; retention per org policy
- [ ] ElastiCache Redis 7 (staging cluster)
- [ ] S3 bucket(s) for `sensitive` + `campaign-media` (Block Public Access ON)
- [ ] KMS CMKs as required

## Compute
- [ ] ECR repos: marcaturshub-api, marcaturshub-worker, marcaturshub-scheduler, marcaturshub-reverb
- [ ] ECS cluster marcaturshub-staging
- [ ] Services: api, worker, scheduler, reverb (desired count ≥ 1 each)
- [ ] Task roles: least privilege S3 / Secrets Manager / CloudWatch
- [ ] ALB + target groups: API :8000, Reverb :8080 (WSS)
- [ ] ACM certs on ALB / CloudFront

## Edge / DNS
- [ ] Route 53 records for real staging hosts (api / app / admin / ws) — **do not invent names**
- [ ] CloudFront for Participant + Admin SPAs
- [ ] Optional CloudFront in front of API ALB

## Secrets
- [ ] Secrets Manager `marcaturshub/staging/app` (keys from `.env.staging.example`)
- [ ] ECS injects secrets; nothing baked into images
- [ ] GitHub Environment `staging` OIDC role for ECR push + ECS update

## Observability
- [ ] CloudWatch log groups per service
- [ ] ALB 5xx alarm
- [ ] ECS unhealthy task alarm
- [ ] Queue failure / scheduler failure visibility (log-based)

## Explicitly out of scope until staging GREEN
- [ ] Production account cutover
- [ ] Production DNS
- [ ] Production Paystack live keys

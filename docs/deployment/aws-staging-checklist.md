# AWS staging resource checklist (ENG-040A)

Apply only after AWS account access exists. Prefer IaC (CloudFormation/Terraform) owned by Ops — this checklist prevents inventing a different topology.

## Network

- [ ] VPC with public + private subnets
- [ ] NAT for private ECS tasks
- [ ] Security groups: ALB → ECS api/reverb; ECS → RDS/Redis/S3 endpoints
- [ ] RDS and Redis: private only

## Data

- [ ] RDS MySQL 8 (utf8mb4), staging instance class
- [ ] ElastiCache Redis 7
- [ ] S3 bucket(s) for `sensitive` + `campaign-media` prefixes (Block Public Access)
- [ ] KMS keys as required by org policy

## Compute

- [ ] ECR repositories: `marcaturshub-api`, `worker`, `scheduler`, `reverb`
- [ ] ECS cluster `marcaturshub-staging`
- [ ] Services: api, worker, scheduler, reverb (desired count ≥ 1 each for api/worker/scheduler/reverb)
- [ ] Task roles: least privilege S3/Secrets/CloudWatch
- [ ] ALB + target groups: HTTP API :8000, WebSocket :8080 (or path-based)
- [ ] ACM certs attached to ALB / CloudFront

## Edge / DNS

- [ ] Route 53: `api.`, `app.`, `admin.`, `ws.` staging records
- [ ] CloudFront distributions for Primary FE + Admin FE static assets
- [ ] CloudFront origin for API (optional) or direct ALB DNS

## Secrets

- [ ] Secrets Manager secret `marcaturshub/staging/app` mirroring `.env.staging.example` keys
- [ ] ECS inject secrets as environment
- [ ] GitHub Environment `staging` OIDC role for ECR push / ECS deploy

## Observability

- [ ] CloudWatch log groups per service
- [ ] ALB 5xx alarms
- [ ] ECS service unhealthy task alarms

## Explicitly out of ENG-040A

- Production account resources
- Production DNS cutover
- Production Paystack live keys

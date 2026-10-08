# ENG-040 staging foundation CloudFormation scaffold
#
# DEFERRED (MH-OPS-006): ECR/ECS is the future scale path. The pilot uses
# Lightsail (see infra/lightsail/ and docs/deployment/production.md). Private S3
# buckets for production are created by hand per docs/deployment/production-checklist.md.
#
# Creates ECR repositories and private S3 buckets only.
# Does NOT create VPC/ECS/RDS/ALB (those require org-specific networking).
# See docs/deployment/aws-staging-checklist.md for the full resource list.
#
# Never commit AWS account IDs or real domain defaults into parameters files.
# Use a local (gitignored) params JSON when deploying.

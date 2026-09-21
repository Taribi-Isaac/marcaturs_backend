# ENG-040 staging foundation CloudFormation scaffold
#
# Creates ECR repositories and private S3 buckets only.
# Does NOT create VPC/ECS/RDS/ALB (those require org-specific networking).
# See docs/deployment/aws-staging-checklist.md for the full resource list.
#
# Never commit AWS account IDs or real domain defaults into parameters files.
# Use a local (gitignored) params JSON when deploying.

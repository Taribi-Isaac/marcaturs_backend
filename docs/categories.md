# Categories

Administrator-configurable marketplace taxonomy for campaign association.

## Rules

- Flat list (no parent/child tree in the approved documents).
- Not seeded with a fixed Nigerian sector list; administrators create names after legal/compliance review.
- `listing_status`: `allowed` | `restricted` | `prohibited`.
- `is_active`: deactivate without deleting rows (campaigns restrict-on-delete).
- Public `GET /api/v1/categories` returns active categories that are not prohibited.
- Only ADMIN mutates definitions.

## Profile vs campaign

`business_profiles.category` is still optional descriptive text on the profile. Campaigns use `categories.id`. There is no automatic migration of free-text profile values into the taxonomy.

## Deferred

Restricted-category extra verification, prohibited enforcement beyond assignment, admin UI, Redis cache of the public list.

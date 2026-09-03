# Domain layout

The Technical Architecture Document specifies a modular monolith with logical modules such as Authentication, Users, Businesses, Ambassadors, Campaigns, Deals, Commissions, and Audit.

Implemented so far: identity/auth, profiles, verification, categories, campaign shells, versions, lifecycle, paid extensions, marketplace discovery, marketing resources, Business–Ambassador chat, chat message broadcast, Deal foundation, Deal payment evidence, Deal payment confirmation / sealing, Commission liability, Commission settlement recording (`due → paid → received`), and **Commission overdue detection** (derived `is_overdue` + audit event; no reminders). Remaining: commission reminders/dispute, Featured, certification payments. Production WebSocket infrastructure is pending.


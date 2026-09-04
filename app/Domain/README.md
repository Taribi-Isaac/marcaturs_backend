# Domain layout

The Technical Architecture Document specifies a modular monolith with logical modules such as Authentication, Users, Businesses, Ambassadors, Campaigns, Deals, Commissions, and Audit.

Implemented so far: identity/auth, profiles, verification, categories, campaign shells, versions, lifecycle, paid extensions, marketplace discovery, marketing resources, Business–Ambassador chat, chat message broadcast, Deal foundation through completion, Commission liability/settlement/overdue/reminders, Notifications foundation, and **Dispute foundation** (Report Issue + Admin investigation; no financial mutation). Remaining: Featured, certification payments, cancellation/refund. Production WebSocket infrastructure is pending.


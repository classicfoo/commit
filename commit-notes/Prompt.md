Act as a senior full-stack engineer.

Goal
- Build a working prototype for my accountability app (“Commit”) based strictly on the business rules and core concepts I provided.
- If anything is ambiguous, make the smallest reasonable assumption and list it in a “Assumptions” section in the README.

My obsidian notes should already be in the 'prod' branch of the repository. (Do not invent new domain rules beyond this. You may infer obvious implementation details.)

Non-negotiable domain rules
- Entities: User, Commitment, Requirement, Post, Subscription.
- Any User may create a Post on any Commitment.
- Posts have a type: `check_in` or `comment`.
- Only the Commitment owner’s `check_in` posts are evaluated against Requirements.
- `comment` posts never satisfy Requirements.
- Users subscribe to Commitments (not to users).
- Subscriptions control notifications/feed visibility only, not posting permissions.

Prototype scope (MVP screens)
1) Authentication (simple) 
This is already done. No changes needed.

2) Commitments
- List commitments
- View a single commitment (details, requirements, posts)
- Create a commitment (title + description)
- Set owner as the logged-in user

3) Requirements
- Add requirements to a commitment (at least these three types):
  - post_frequency (e.g. “at least 1 check_in per day”)
  - text_update (check_in must include text)
  - image_required (check_in must include an image URL for MVP)
- Store requirement parameters in a simple way (either separate columns or JSON).

4) Posts
- On a commitment page, allow creating:
  - a check_in post
  - a comment post
- Anyone logged in can post.
- If type is check_in and requirement includes image_required, enforce “image URL required”.
- If type is check_in and requirement includes text_update, enforce “text required”.

5) Evaluation
- On commitment page, show “Status (today)” based on requirements:
  - Evaluate only check_in posts by the owner.
  - For post_frequency daily: count owner check_ins today (local timezone is fine for MVP).
  - Show pass/fail + simple explanation.

6) Subscriptions & Notifications (very light)
- Allow a user to subscribe/unsubscribe to a commitment.
- Add an in-app notifications page:
  - When a new check_in happens, create notifications for subscribers (excluding the author).
  - Comments: do NOT notify in MVP (leave a TODO).
- Notification fields: recipient_user_id, commitment_id, post_id, created_at, read_at (nullable).

Deliverables
- A small repo-style project layout with:
  - `README.md` with setup, assumptions, and how to use the prototype
  - SQL migration or auto-create tables on first run
  - Seed script or seeding on first run
  - Minimal but usable HTML pages (no styling required beyond basic)

Implementation details
- Use prepared statements everywhere.
- Keep it secure enough for a prototype: password_hash/password_verify, session cookies.
- Use basic routing via query params (e.g., ?r=commitment&id=1) or a tiny router.
- Avoid overengineering. Prefer clarity.

Finish criteria
- I can run it, login as demo users, create commitments, add requirements, post check_ins/comments, subscribe, and see today’s status + notifications for check_ins.

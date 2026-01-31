# README

**Waitpress is a Wordpress Plugin designed to support a community garden waitlist application**.

## Plugin Structure and Features:
1. Apply to the Waitlist.
    - Allows for prospective applications to apply to the waitlist
    - Form submission requires name, email, phone, address/eligibility check, and comments
    - Upon submission, the applicant sees "You're on the list, check status here" and receives a confirmation email with a "magic link" to check their status.
    - An admin also receives a notification

2. Checking current status and position, no login required
    - Applicants can either use the original "magic link" from their confirmation email, or enter their email to receive a onetime link
    - Using the link, applicants can see their current status (either `waiting`, `offered`, `accepted`, `removed`, or `assigned`), and last updated date.
    - If `offered`, then the applicant can see the details and use either `Accept` or `Decline` buttons to send a response to the admin
    - Applicants will also always see a button providing the option to `Leave Waitlist` 
    - An applications waitlist position = count of active `waiting` entries ordered by `joined_at` (plus a tie-breaker ID)
    - All applicants automatically receive a monthly email containing their current position on the waitlist and a direction to the status page to leave the waitlist if they'd like. The email template can be configured by Admins.

3. Leaving the waitlist
    - If an applicant clicks the `Leave Waitlist` button on the status page, they'll get a confirmation dialog where they can confirm they'd like to leave
    - Upon leaving the waitlist, their status is marked to `removed`. They must re-apply to join the waitlist again, and will restart at the bottom even if all their info is the same.
    - The applicant will get an email confirming they left the waitlist.

4. Offer the next person, accept/decline, and auto-advance
    - Admin clicks "Offer next eligible"
    - App then selects next `waiting` applicant (usually the oldest submission still on the list, but not always)
    - Creates an offer row with
        - `offer_token` (random, single-use)
        - `expires_at` (default of 5 days, but adjustable by the admin )
        - optional `plot_id` that an admin can set if they're offering a specific plot
    - After offered, the applicant's status on the waitlist becomes `offered`
    - An email is sent to the applicant and admin with links to either "Accept Offer" (e.g. `https://site.com/waitlist/offer/?token=...`) or "Decline and be removed from the Waitlist".
    - If the applicant selects to "Accept Offer", then:
        - The applicant status becomes `accepted`, and sends a confirmation email to both the applicant and admin
    - If the applicant selects to "Decline and be removed from the Waitlist", then:
        - The applicant status becomes `removed`, and the offer is closed, with a confirmation email sent to both the applicant and the admin
        - The next person on the waitlist is automatically sent the same offer, with expected notification emails. Note that this happens when an applicant Declines an offer, but *not* when an applicant Accepts an offer.
    - Offers do expire. A schedulied job runs daily and:
        - finds expired offers still pending
        - marks the offer expired and moves the applicant to the bottom of the waitlist, updating their position number.
        - notifies admin
        - the next person on the waitlist is automatically sent the same offer, with expected notification emails.

5. Admin UI (Wordpress Dashboard)
Admins have a menu called "Garden Waitlist"
- Waitlist entries table with filters and search
- "offer next" button and corresponding function
- Waitlist and Offer history
- Plot inventory to be offered
- Settings (Offer expiration days, Monthly email day/time, email templates)

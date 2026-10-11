# Verification email delivery

Verification uses Firebase one-time links delivered through the existing coffeybean.com SMTP mailbox. Firebase continues to validate the link and mark the email verified; the app never marks an email verified itself.

POST /api/v1/me/verification-email requires a valid Firebase bearer token. It ignores client-selected recipients, checks the current Firebase UID/email match and enabled/unverified status, then generates VERIFY_EMAIL with returnOobLink=true. SMTP receives the authenticated account email only. The response contains sent=true after SMTP acceptance, never the verification link or credentials. SMTP acceptance does not prove inbox delivery.

The app sends once after signup, and thereafter only when the user presses Resend verification email. A successful signup attempt is deduplicated within the app session. Server errors remain visible on the verification screen; there is no automatic fallback to Firebase email sending.

Activation prerequisites:
- Add firebaseauth.users.sendEmail to the existing householdInvitationLookup role assigned to household-invite-lookup in coffey-table-recipes. Preserve users.get and users.delete; no broad administrator role or new key is needed.
- Add coffeybean.com to Firebase Authentication authorized domains so Firebase accepts the configured continuation URL. After verification the Continue action opens account=signin with the email prefilled.
- Deploy index.php, src/bootstrap.php, src/FirebaseAccountDirectory.php, src/VerificationService.php and src/VerificationMail.php to the established hosted server. Existing SMTP settings and embedded email artwork are reused.
- Test an explicitly chosen unverified account, confirm receipt and link redemption, then check Firebase and the app both report verified. Do not log action links or credentials.

Validation: php tests/verification.php checks recipient binding, invalid identities, link-generation failure, SMTP message content and safe HTML links without sending mail. Flutter verification_delivery_test.dart checks bearer authentication, an empty request body, and propagation of SMTP errors. Existing verification/invitation widgets check single signup attempts, button-only resends, and invitation gating.

Implementation prepared on 2026-10-11; activation and real delivery are pending Firebase permission/domain setup and hosted backend deployment. The hosted app is not updated by this work: the project workflow requires an explicit hosted-app update request and a tagged version.

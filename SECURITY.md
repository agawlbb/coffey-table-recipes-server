# Hosted-service access

The OpenAI key resides only in protected server configuration. Making the source
public does not publish that key, alter account permissions, or authorize anonymous
requests. Existing website and mobile clients already use these API endpoints.

AI routes verify the Firebase ID token for this deployment's project, reject
disabled local accounts, and check household membership. A new person must obtain
an account in this Firebase project and create or join a household. The hosted app
offers registration; Firebase authentication APIs can also register/sign in without
visiting the website. Eligible accounts can use the app or call the API directly.
The authorization boundary is account identity and household membership, not a
browser Origin header, a GitHub account, or access to the source code.

At the owner's request, recipe imports have no application hourly request quota.
Legacy 20-request counter files are ignored. Input limits, strict output validation,
request timeouts and backend authentication remain. Upstream provider limits can
still apply. Image generation retains its existing concurrency and existing-image
checks. Public source adds no API permissions, but no internet deployment can be
guaranteed immune to abuse by eligible or compromised accounts.

For administration, the verified token's Firebase UID selects the database user
record. Only an enabled record with account_role=administrator can perform privileged
operations. Google sign-in can supply the identity, but the token alone cannot
grant a role. An email change preserves the role for the same UID; another UID
does not gain it by sharing an email address. New installations need an explicit
role assignment by a trusted database operator.

The public recipe share page intentionally permits ID-based recipe reading.
Private server configuration, account data and raw imports must remain protected.
See .htaccess, config/.htaccess and storage/.htaccess for deployment requirements.
Independent installations must supply their own credentials and authentication
project. Third-party forks do not receive the owner's hosted secrets.

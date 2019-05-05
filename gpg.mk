#-*-tab-width: 4; fill-column: 76; whitespace-line-column: 77 -*-
# vi:shiftwidth=4 tabstop=4 textwidth=76

keyUrl=https://www.mediawiki.org/keys/keys.txt

# Fetch PGP keys from keyUrl
.PHONY:
fetchKeys:
	mkdir -p $$GNUPGHOME
	chown `id -u` $$GNUPGHOME
	chmod 700 $$GNUPGHOME
	gpg --fetch-keys ${keyUrl}

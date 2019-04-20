# -*- tab-width: 4 -*-

keyUrl=https://www.mediawiki.org/keys/keys.txt

.PHONY:
fetchKeys:
	mkdir -p $$GNUPGHOME
	chown `id -u` $$GNUPGHOME
	chmod 700 $$GNUPGHOME
	gpg --fetch-keys ${keyUrl}

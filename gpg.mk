#-*-tab-width: 4; fill-column: 76; whitespace-line-column: 77 -*-
# vi:shiftwidth=4 tabstop=4 textwidth=76

keyUrl=https://www.mediawiki.org/keys/keys.html
gpgDir=${workDir}/gpg
myGpg=$(shell HOME=${oldHOME} gpgconf --list-dirs |							\
		awk -F: '$$1 == "homedir" {print $$2}')

# Continue without signature after downloading
noSigOk ?= false
export noSigOk
doNotFail=$(if $(filter-out true,${noSigOk}),true,false)

# Sign the release
doSign ?= false
export doSign

# KeyID to use
keyId ?= $(shell git config --get user.signingkey || (						\
	gpgconf --list-options gpg |											\
	awk -F: '$$1 == "default-key" {print $$10}' | sed s,^.,,) )
export keyId

# Fetch PGP keys from keyUrl
.PHONY:
fetchKeys:
	mkdir -p ${gpgDir}
	sudo chown `id -u` ${gpgDir}
	chmod 700 ${gpgDir}
	wget -q -O - ${keyUrl} | gpg --homedir=${gpgDir} --import

# Show information about the key used for signing.
showKeyInfo:
	gpg --list-key ${keyId}

# Verify a signature for a file
verifyFile:
	test -n "${sigFile}" -a -f ${sigFile} || (								\
		echo "The sigFile (${sigFile}) does not exist.";					\
		exit 2																\
	)
	(																		\
		verify=`gpg --batch --verify ${sigFile} 							\
											$(basename ${sigFile}) 2>&1`;	\
		echo $$verify | grep -q 'Good signature'							\
			&& gpg --batch --verify ${sigFile} $(basename ${sigFile})		\
			|| (															\
				test "${getUnknownKeys}" = "false"							\
				&& echo "Cannot verify file because we don't have the key"	\
				|| (														\
					key=`echo $$verify |									\
						sed 's,.*gpg: using [^ ]* key \([^ ]*\).*,\1,'`;	\
					gpg --recv $$key &&										\
					gpg --batch --verify ${sigFile} $(basename ${sigFile})	\
				)															\
			)																\
	)

#
verifyKeyIDSet:
	test -n "{$keyId}" || (													\
		echo ${indent}"Please specify a keyId!";							\
		echo; exit 1;														\
	)

verifySecretKeyExists: verifyKeyIDSet
	gpg --homedir=${gpgDir} --list-secret-keys ${keyId}						\
													> /dev/null 2>&1 || (	\
		echo ${indent}"No secret key matching '${keyId}' in the keyring at";\
		echo ${indent} ${gpgDir}.; echo;									\
		${MAKE} checkForSecretKeyInMainKeyring keyId=${keyId}				\
		echo; exit 1														\
	)

checkForSecretKeyInMainKeyring: verifyKeyIDSet
	gpg --homedir=${myGpg} --list-secret-keys ${keyId} > /dev/null 2>&1 && (\
		echo ${indent}"Secret key exists in ${myGpg}!";						\
		echo ${indent}"Use 'make copySecretKey' to copy it."; echo			\
	) || (																	\
		echo ${indent}"No secret key matching '${keyId}' in the keyring at";\
		echo ${indent}${myGpg}.												\
	)

copySecretKey: verifyKeyIDSet
	(																		\
		gpg --homedir=${myGpg} --export-secret-key ${keyId};				\
		gpg --homedir=${myGpg} --export ${keyId};							\
	) | gpg --homedir=${gpgDir} --import


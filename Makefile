# -*- tab-width: 4; fill-column: 73 -*-
# vi:shiftwidth=4 tabstop=4 textwidth=73

# from https://stackoverflow.com/questions/18136918/how-to-get-current-relative-directory-of-your-makefile
mkfile_path := $(abspath $(lastword $(MAKEFILE_LIST)))
current_dir := $(patsubst %/,%,$(dir $(mkfile_path)))
include ${current_dir}/help.mk
include ${current_dir}/config.mk
include ${current_dir}/gpg.mk

# Checkout, tag, and build a tarball
tarball: tag doTarball

# Just build tarball with already checkedout code
doTarball: verifyReleaseGiven verifyPreviousVersion getMakeRelease		\
		getPreviousTarball git-archive-all verifyWgVersion
	test -f ${mwDir}/.git/config || (									\
		echo ${indent}"Check out repo first: make tarball";				\
		echo; exit 1													\
	)

	mkdir -p ${targetDir}
	${makeRelease} --previous ${prevReleaseVer} --sign					\
		--output_dir ${targetDir} ${mwDir} ${releaseVer}

#
showPreviousRelease:
	echo ${prevReleaseVer}

# Retreive all artifacts from the release server before releaseVer.
getAllTarballs:
	${MAKE} getPreviousTarball releaseVer=${prevReleaseVer} &&			\
		${MAKE} getAllTarballs releaseVer=${prevReleaseVer}
#
getPreviousTarball:
	# Fork another, or we get recursiveness
	${MAKE} downloadTarball releaseVer=${prevReleaseVer}				\
		majorReleaseVer=${prevMajorVer}

# Download all artifacts for a release.
downloadTarball:
	test -n "${thisMinorVer}" || (										\
		echo ${indent}"Minor version not found in '${releaseVer}'!";	\
		echo; exit 1													\
	)

	${MAKE} downloadAndVerifyFile 										\
		targetFile=mediawiki-core-${releaseVer}.tar.gz || ${doNotFail}
	${MAKE} downloadAndVerifyFile 										\
		targetFile=mediawiki-${releaseVer}.tar.gz || ${doNotFail}
	${MAKE} downloadAndVerifyFile										\
		targetFile=mediawiki-${releaseVer}.patch.gz || ${doNotFail}
	${MAKE} downloadAndVerifyFile 										\
		targetFile=mediawiki-i18n-${releaseVer}.patch.gz || true

#
downloadAndVerifyFile:
	${MAKE} ${targetDir}/${targetFile}
	test ! -f ${targetDir}/${targetFile} ||								\
		${MAKE} downloadFile targetFile=${targetFile}.sig || ${noSigOk}
	test ! -f ${targetDir}/${targetFile} -o								\
		! -f ${targetDir}/${targetFile}.sig ||							\
		${MAKE} verifyFile targetFile=${targetFile}


${targetDir}/${targetFile}: downloadFile
downloadFile:
	mkdir -p ${targetDir}

	test -f ${targetDir}/${targetFile} || (								\
		echo -n Downloading ${targetFile}...;							\
		${WGET} ${releasesUrl}${majorReleaseVer}/${targetFile}			\
			-O ${targetDir}/${targetFile} || (							\
			echo; echo Could not download ${targetFile}; 				\
			echo; exit 1												\
		) || exit 1;													\
		echo															\
	)


verifyFile:
	gpg --batch --verify ${targetDir}/${targetFile}.sig					\
		${targetDir}/${targetFile} || (									\
		echo Could not verify ${targetFile};							\
		echo; exit 1													\
	)
	echo Successfully verified ${targetFile}
	echo

getMakeRelease: repo=${gerritHead}/mediawiki/tools/release
getMakeRelease: cloneDir=${releaseDir}
getMakeRelease: branch=master
getMakeRelease: clone

# Tag the checkout with the releaseVer.
tag: verifyReleaseGiven verifyTagNotExist verifyPrivateKeyExists
	${MAKE} ${mwDir}/${relBranch}

	cd ${mwDir}/${relBranch}
	(																	\
		export modules=`${GIT} status -s extensions skins |				\
			awk '{print $$2}'`;											\
		test -z "$$modules" || (										\
			echo ${indent}"Committing submodules: $$modules";			\
			${GIT} add $$modules;										\
			${GIT} commit -m "Updating submodules for ${releaseVer}"	\
				$$modules												\
		)																\
	)

	test `${GIT} status -s | wc -l` -eq 0 || (							\
		echo ${indent}"There is uncommitted work!";						\
		echo; exit 1													\
	)

	test -n "$(filter-out true,${doTags})" || (							\
		echo Tagging submodules...;										\
		cd ${mwDir};													\
		${GIT} submodule -q	foreach										\
			git tag -a ${releaseVer} -m ${releaseTagMsg};				\
		echo Tagging core...;											\
		${GIT} tag -a ${releaseVer} -m ${releaseTagMsg}					\
	)

# Remove the tag specified in releaseVer.
maybeSubmodules=$(if $(filter-out false,${fetchSubmodules}),			\
	--recurse-submodules)

removeTag: verifyReleaseGiven
	${GIT} fetch ${maybeSubmodules}
	(																	\
		cd ${mwDir};													\
		${GIT} submodule foreach										\
			'git tag -d ${releaseVer} ${force}';						\
	)
	${GIT} tag -d ${releaseVer}

#
clone:
	test -e ${cloneDir}/.git || (										\
		echo ${indent}"Cloning ${repo} to $${cloneDir} (${branch})";	\
		git clone ${maybeSubmodules} -b ${branch} ${repo}				\
			${cloneDir}													\
	) && (																\
		cd ${cloneDir};													\
		git fetch;														\
		export branches=\|`git branch | sed 's,$$,|,'`					\
		echo $branches | fgrep -q '|  ${branch}|' || (					\
			git checkout ${branch}										\
		) && (															\
			echo $branches | fgrep -q '|* ${branch}|' || (				\
				git checkout ${branch};									\
				git pull												\
			) && (														\
				git pull												\
			)															\
		);																\
		echo ${indent}"Updating ${repo} in ${cloneDir}";				\
		git fetch ${maybeSubmodules}									\
	)

${mwDir}/${relBranch}:
	test "${relBranch}" != "---" || (									\
		echo ${indent}"No release branch given";						\
		echo; exit 1													\
	)
	${MAKE} clone cloneDir=${mwDir}/master repo=${mwGit} branch=master
	${MAKE} clone cloneDir=${mwDir}/${relBranch} repo=${mwDir}/master	\
		branch=${relBranch}

# Show revision matching HEAD.
showHeadRev: fetchSubmodules=false
showHeadRev: ${mwDir}/${relBranch}
	${GIT} log -1 --oneline

# Show information about the key used for signing.
showKeyInfo:
	gpg --list-key ${keyId}

# Make sure the releaseVer tag is signed correctly.
verifyTag: verifyReleaseGiven
	${MAKE} ${mwDir}/${relBranch}
	echo ${indent}"Checking core"
	${GIT} verify-tag ${releaseVer} || (								\
		echo ${indent}"Cannot verify signature on tag '${releaseVer}'";	\
		echo; exit 1													\
	)
	test "${revision}" = "HEAD" || (									\
		test "`${GIT} log -1 ${releaseVer} --format=%H`" =				\
			"${revision}" || (											\
				echo ${indent}"Wrong revision tagged.";					\
				echo; exit 1											\
		)																\
	)
	(																	\
		cd ${mwDir};													\
		${GIT} submodule foreach
			'git fetch && git verify-tag ${releaseVer} || (				\
				echo -n Could not verify signature on;					\
				echo ${indent}" ${releaseVer} for $$name";				\
				echo; exit 1											\
			)';															\
	)

#
verifyReleaseGiven:
	test "${releaseVer}" != "---" || (									\
		echo ${indent}"Please specify releaseVer!";						\
		echo; exit 1													\
	)

verifyPrivateKeyExists:
	test -n "{$keyId}" || (												\
		echo ${indent}"Please specify a keyId!";						\
		echo; exit 1;													\
	)
	gpg --list-secret-keys ${keyId} > /dev/null 2>&1 || (				\
		echo ${indent}"No private key matching '${keyId}'"; 			\
		echo; exit 1													\
	)


verifyRevisionExists: ${mwDir}/${relBranch}
	$(if $(filter-out 0,$(shell ${GIT} ls-tree ${revision} | wc -l)),	\
		exit 0,															\
		exit 1															\
	)

verifyTagNotExist: verifyReleaseGiven
	${MAKE} clone cloneDir=${mwDir}/master repo=${mwGit}				\
		branch=${relBranch}
	test -n "$(filter-out true,${doTags})" -o -z "`cd ${mwDir};			\
		${GIT} log -1 --oneline ${releaseVer} 2> /dev/null`" || (		\
			echo ${indent}"Release tag already set!";					\
			echo; exit 1												\
	)

verifyPreviousVersion:

verifyWgVersion:
	${GIT} grep -q "\$$wgVersion = \'${releaseVer}\'" -- ${defSet} || (	\
		echo ${indent}"\$$wgVersion is not set to"						\
			"${releaseVer} in ${defSet}!";								\
		${GIT} grep "\$$wgVersion = \'" -- ${defSet};					\
		exit 2															\
	)

# This was useful when we put the Makefile together, but now this done
# in the creation of the docker image.
#
installGitArchiveAll:
	pip list --format=legacy | grep git-archive-all > /dev/null ||		\
		pip install git-archive-all

git-archive-all:
	python3 -c 'import git_archive_all' || (							\
		echo ${indent}"Installing git-archive-all";						\
		pip3 install $@ || (											\
			echo Try \"sudo make git-archive-all\";						\
			exit 2														\
		)																\
	)

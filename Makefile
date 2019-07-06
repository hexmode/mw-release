#-*-tab-width: 4; fill-column: 76; whitespace-line-column: 77 -*-
# vi:shiftwidth=4 tabstop=4 textwidth=76
# Copyright (C) 2019  Wikimedia Foundation, Inc.

# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.

# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.

# You should have received a copy of the GNU General Public License
# along with this program.  If not, see <http://www.gnu.org/licenses/>.

# from https://stackoverflow.com/questions/18136918/how-to-get-current-relative-directory-of-your-makefile
mkfilePath := $(abspath $(lastword $(MAKEFILE_LIST)))
mkfileDir := $(patsubst %/,%,$(dir $(mkfilePath)))
include ${mkfileDir}/help.mk
include ${mkfileDir}/config.mk
include ${mkfileDir}/gpg.mk

${workDir}:
	echo ${workDir}
	test -d ${workDir} || (													\
		mkdir -p ${workDir}													\
	)

# Checkout, tag, and build a tarball
tarball: tag doTarball

# Just build tarball with already checked out code
doTarball: verifyReleaseGiven getMakeRelease getPreviousTarball				\
		git-archive-all verifyWgVersion
	test -f ${mwDir}/${relBranch}/.git/config || (							\
		echo ${indent}"Check out repo first: make tarball";					\
		echo; exit 1														\
	)

	mkdir -p ${targetDir}
	${makeRelease} --previous ${prevReleaseVer}								\
		$(if $(subst false,,${doSign}),--sign)								\
		--output_dir ${targetDir} ${mwDir}/${relBranch} ${releaseVer}

#
showPreviousRelease:
	echo "${prevReleaseVer}"

# Retreive all artifacts from the release server before releaseVer.
getAllTarballs: downloadTarball
	${MAKE} getAllTarballs releaseVer=${prevReleaseVer}

#
getPreviousTarball:
	# Fork another, or we get recursiveness
	${MAKE} downloadTarball releaseVer=${prevReleaseVer}					\
		majorReleaseVer=${prevMajorVer}

# Download all artifacts for a release.
downloadTarball:
	test -n "${thisMinorVer}" -a "${thisMinorVer}" != "---" || (				\
		echo ${indent}"Minor version not found in '${releaseVer}'!";		\
		echo; exit 1														\
	)

	${MAKE} downloadAndVerifyFile											\
		targetFile=mediawiki-core-${releaseVer}.tar.gz || ${doNotFail}
	${MAKE} downloadAndVerifyFile 											\
		targetFile=mediawiki-${releaseVer}.tar.gz || ${doNotFail}
	${MAKE} downloadAndVerifyFile											\
		targetFile=mediawiki-${releaseVer}.patch.gz || ${doNotFail}
	${MAKE} downloadAndVerifyFile 											\
		targetFile=mediawiki-i18n-${releaseVer}.patch.gz || true

#
downloadAndVerifyFile:
	test -f ${targetDir}/${targetFile} -a ${targetDir}/${targetFile}.sig ||(\
		${MAKE} downloadFile targetFile=${targetFile} &&					\
		${MAKE} downloadFile targetFile=${targetFile}.sig					\
	)
	${MAKE} verifyFile sigFile=${targetDir}/${targetFile}.sig

${targetDir}/${targetFile}: downloadFile
downloadFile:
	mkdir -p ${targetDir}

	test -f ${targetDir}/${targetFile} || (									\
		echo -n Downloading ${targetFile}...;								\
		${WGET} ${releasesUrl}${majorReleaseVer}/${targetFile}				\
			-O ${targetDir}/${targetFile} || (								\
			echo; echo Could not download ${targetFile}; 					\
			rm ${targetDir}/${targetFile};									\
			echo; exit 1													\
		)																	\
	)

getMakeRelease: repo=${gerritHead}/mediawiki/tools/release
getMakeRelease: cloneDir=${releaseDir}
getMakeRelease: branch=master
getMakeRelease: clone

commitCheck:
	test -n "${gitCommitEmail}" ||											\
		( echo ${indent}"Set gitCommitEmail!"; exit 2 )
	test -n "${gitCommitName}" ||											\
		( echo ${indent}"Set gitCommitName!"; exit 2 )

# Tag the checkout with the releaseVer.
tag: verifyReleaseGiven verifySecretKeyExists commitCheck
	${MAKE} ${mwDir}/${relBranch}

	(																		\
		export modules="`${GIT} status -s extensions skins | 				\
			awk '{print $$2}'`";											\
		test -z "$$modules" || (											\
			echo ${indent}"Committing submodules: $$modules";				\
			${GIT} add -f $$modules;										\
			${GIT} commit -m "Updating submodules for ${releaseVer}"		\
				$$modules													\
		)																	\
	)

	test `${GIT} status -s | wc -l` -eq 0 || (								\
		echo ${indent}"There is uncommitted work!";							\
		echo; exit 1														\
	)

	test -n "$(filter-out true,${doTags})" || (								\
		echo Tagging submodules... &&										\
		cd ${mwDir}/${relBranch} &&											\
		${GIT} submodule -q	foreach											\
			git tag -a ${releaseVer} -m ${releaseTagMsg} &&					\
		echo Tagging core... &&												\
		${GIT} tag -a ${releaseVer} -m ${releaseTagMsg}						\
	)

# Remove the tag specified in releaseVer.
maybeSubmodules=$(if $(filter-out false,${fetchSubmodules}),				\
	--recurse-submodules)

removeTag: verifyReleaseGiven
	${GIT} fetch ${maybeSubmodules}
	(																		\
		cd ${mwDir}/${relBranch};											\
		${GIT} submodule foreach											\
			'git tag -d ${releaseVer} ${force}';							\
	)
	${GIT} tag -d ${releaseVer}

#
clone:
	echo ${indent}"Checking if this branch is in the remote"
	git ls-remote --exit-code --heads ${mwGit} ${relBranch} ||				\
		${makeBranch} -n ${relBranch} -p ${mwDir}/master tarball
	test -e ${cloneDir}/.git && (											\
		echo ${indent}"Updating ${repo} in ${cloneDir}";					\
		cd ${cloneDir};														\
		git fetch;															\
		export branches="`git branch | sed "s,$$,|,"`";						\
		echo "$$branches" | fgrep -q '* ${branch}|' || (					\
			echo git checkout ${branch}										\
		);																	\
		git pull ${maybeSubmodules}											\
	) || (																	\
		echo ${indent}"Cloning ${repo} to $${cloneDir} (${branch})";		\
		git clone ${maybeSubmodules} ${repo} ${cloneDir};					\
		${MAKE} fixRemote;													\
		git checkout ${branch}												\
	)

fixRemote:
	test ! -e ${repo} -a "`${GIT} remote get-url origin`" != "${repo}" || (	\
		echo ${indent}"Changing remote for ${cloneDir}";					\
		cd ${cloneDir};														\
		git remote set-url origin ${mwGit};									\
		git fetch origin													\
	)

${mwDir}/${relBranch}:
	test "${relBranch}" != "---" || (										\
		echo ${indent}"No release branch given";							\
		echo; exit 1														\
	)
	${MAKE} clone cloneDir=${mwDir}/master repo=${mwGit}					\
		branch=master
	${MAKE} clone cloneDir=${mwDir}/${relBranch}							\
		repo=${mwDir}/master branch=${relBranch}

# Show revision matching HEAD.
showHeadRev: fetchSubmodules=false
showHeadRev: ${mwDir}/${relBranch}
	${GIT} log -1 --oneline

# Make sure the releaseVer tag is signed correctly.
verifyTag: verifyReleaseGiven
	${MAKE} ${mwDir}/${relBranch}
	echo ${indent}"Checking core"
	${GIT} verify-tag ${releaseVer} || (									\
		echo ${indent}"Cannot verify signature on tag"						\
			"'${releaseVer}'";												\
		echo; exit 1														\
	)
	test "${revision}" = "HEAD" || (										\
		test "`${GIT} log -1 ${releaseVer} --format=%H`" =					\
			"${revision}" || (												\
				echo ${indent}"Wrong revision tagged.";						\
				echo; exit 1												\
		)																	\
	)
	(																		\
		cd ${mwDir}/${relBranch};											\
		${GIT} submodule foreach											\
			'git fetch && git verify-tag ${releaseVer} || (					\
				echo -n Could not verify signature on;						\
				echo ${indent}" ${releaseVer} for $$name";					\
				echo; exit 1												\
			)';																\
	)

#
verifyReleaseGiven:
	test "${releaseVer}" != "---" || (										\
		echo ${indent}"Please specify releaseVer!";							\
		echo; exit 1														\
	)

verifyRevisionExists: ${mwDir}/${relBranch}
	$(if $(filter-out 0,$(shell												\
			${GIT} ls-tree ${revision} | wc -l								\
	)), exit 0, exit 1)

verifyWgVersion:
	${GIT} grep -q 															\
		'$$wgVersion = '\'${releaseVer}\' -- ${defSet} || (					\
			echo ${indent}'$$wgVersion is not set to'						\
			"${releaseVer} in ${defSet}!";									\
			${GIT} grep '$$wgVersion = ' -- ${defSet};						\
			${MAKE} bumpVersion releaseVer=${releaseVer}					\
		)

git-archive-all:
	python3 -c 'import git_archive_all' || (								\
		echo ${indent}"Installing git-archive-all";							\
		pip3 install $@ || (												\
			echo Try \"sudo make git-archive-all\";							\
			exit 2															\
		)																	\
	)

# Bump the version in DefaultSettings.php
bumpVersion:
	sed -i 's,^\($$wgVersion = '\''\)[^'\'']*,\1${releaseVer},'				\
			${mwDir}/${relBranch}/${defSet} &&								\
		${GIT} add ${defSet}

#
checkOkToCommit:
	test `${GIT} status --porcelain | wc -l` -eq 0 || (						\
		${GIT} status --porcelain | awk '/^ M/ {print $$2}' |				\
			xargs --no-run-if-empty ${GIT} add &&							\
		${GIT} status --porcelain | awk '/^ M/ {print $$2}' | (				\
			lines=`wc -l`;													\
			test $$lines -eq 0 || (											\
				echo "Could not automatically commit.  Please fix manually"	\
					"before continuing.";									\
				exit 2														\
			)																\
		)																	\
	)
	touch $@					# Also see post commit where this is rm'd

commit: checkOkToCommit
	${GIT} commit -m ${releaseMsg}
	rm -f checkOkToCommit

verifyExists:
	test -f ${targetDir}/${targetFile} || (									\
		echo "${targetFile} does not exist";								\
		exit 2																\
	)

	${MAKE} verifyFile sigFile=${targetDir}/${targetFile}.sig

diffExists:
	${MAKE} verifyExists targetFile=mediawiki-${releaseVer}.patch.gz

releaseExists:
	${MAKE} verifyExists targetFile=mediawiki-${releaseVer}.tar.gz

extractRelease: releaseExists
	mkdir -p ${extractDir}
	tar -C ${extractDir} -xzf mediawiki-${releaseVer}.tar.gz

# Check diff for a given releasea
checkDiff: diffExists extractRelease extractPrevRelease
	echo ${indent}Applying diff
	cd ${extractDir}/${prevReleaseVer} &&									\
		zcat ${targetDir}/mediawiki-${releaseVer}.patch.gz | patch -p1
	diff -Nur ${extractDir}/${releaseVer} ${extractDir}/${releaseVer}		\
		> this-diff.diff

# Tag (and bump) source with this version
tagBumpedSource:
	${GIT} pull --recurse-submodules=yes
	${MAKE} checkOkToCommit bumpVersion commit tag

# Create the docker image that runs this
createDocker:
	docker build -t ${imageName} -f ${mkfileDir}/Dockerfile ${mkfileDir}

# Test docker creation and use
self-test: commitCheck createDocker
	sudo rm -f src/out-*
	docker run --rm --volume ${mkfileDir}/src:/src ${imageName} 			\
		explain="make args after (and including) this"						\
		tarball																\
		gitCommitEmail=${gitCommitEmail}									\
		gitCommitName=${gitCommitName}										\
		doSign=${doSign}													\
		VERBOSE=${VERBOSE}													\
		doTags=false														\
		releaseVer=$(if														\
			$(subst ---,,${releaseVer}),${releaseVer},1.32.0)

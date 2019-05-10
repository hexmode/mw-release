#-*-tab-width: 4; fill-column: 76; whitespace-line-column: 77 -*-
# vi:shiftwidth=4 tabstop=4 textwidth=76
ifndef VERBOSE
.SILENT: # Don't print commands
phpOpts=-d error_reporting=30711
composerQuiet=--quiet
wgetQuiet=-q
maintQuiet=-q
endif

# Working directory
workDir ?= $(shell test -w /src && echo /src || echo `pwd`/src)
export workDir

#
oldHOME=$(shell getent passwd $$USER | cut -d: -f6)
HOME=${workDir}
export HOME

# Following are variables for commands that need args
GIT=git --no-pager --work-tree=${mwDir}/${relBranch}						\
		--git-dir=${mwDir}/${relBranch}/.git
MAKE=make -f $(abspath $(firstword ${MAKEFILE_LIST})) indent="${indent}\> "	\
	releaseVer=${releaseVer}
WGET=wget ${wgetQuiet}

thisDir=$(patsubst %/,%,$(dir $(abspath$(lastword ${MAKEFILE_LIST}))))
releasesUrl=https://releases.wikimedia.org/mediawiki/

# Source of Gerrit
gerritHead ?= https://gerrit.wikimedia.org/r
export gerritHead

# Whether to get submodules or not
fetchSubmodules ?= true
export fetchSubmodules

# What version is being released
releaseVer ?= ---
export releaseVer

majorReleaseVer=$(strip $(if $(filter-out ---,${releaseVer}),				\
	$(shell echo ${releaseVer} | cut -d . -f 1).$(shell 					\
	echo ${releaseVer} | cut -d . -f 2),---))

thisMinorVer=$(strip $(if $(filter-out ---,${releaseVer}),					\
	$(shell echo ${releaseVer} | cut -d . -f 3),---))
prevMajorVer=${majorReleaseVer}
ifeq "${thisMinorVer}" "---"
	prevMinorVer=-1
else
	prevMinorVer=$(shell (echo ${thisMinorVer}; echo 1 - p) | dc )
endif

# The version to diff against
prevReleaseVer=$(if $(subst ---,,${majorReleaseVer})						\
	,${majorReleaseVer}.${prevMinorVer},---)
# Is thisMinorVer a zero
ifeq "${thisMinorVer}${prevMinorVer}" "0-1"
	# Fine, find the previous version by fetching the list of
	# previous major version releases, sorting them, and getting
	# the last one.  Startup takes a few ms longer.
	prevMajorVer=$(strip $(if $(filter-out ---,${releaseVer}),				\
		$(shell echo ${releaseVer} | cut -d . -f 1).$(shell				\
			echo ${releaseVer} | (cut -d . -f 2; echo 1 - p ) | dc ),---))
	prevReleaseVer=${prevMajorVer}.$(shell									\
			test -f ${workDir}/out-${prevMajorVer} ||						\
				${WGET} -O ${workDir}/out-${prevMajorVer}					\
					${releasesUrl}${prevMajorVer}/)$(shell					\
			cat ${workDir}/out-${prevMajorVer} | awk						\
				'/mediawiki-${prevMajorVer}[0-9.]*.tar.gz"/ {gsub(			\
				/^.*="mediawiki-${prevMajorVer}.|.tar.gz"[^"]*$$/,			\
				""); print}' | sort -n | tail -1)
endif

# The message to add when tagging
releaseTagMsg ?= $(strip $(if $(filter-out ---,${releaseVer}),				\
	"MediaWiki v${releaseVer}",---))
export releaseTagMsg

# The message to add making a release
releaseMsg ?= $(strip $(if $(filter-out ---,${releaseVer}),				\
	"This is MediaWiki v${releaseVer}",---))
export releaseMsg

# Revision to tag or branch on
revision ?= HEAD
export revision

# What is the release branch is
relBranch ?= $(strip $(if $(filter-out ---,${releaseVer}),					\
	REL$(shell echo ${releaseVer} | cut -d . -f 1)_$(shell					\
	echo ${releaseVer} | cut -d . -f 2),---))
export relBranch

# MediaWiki checkout directory
mwDir=${workDir}/mediawiki
export mwDir

# Check for tags to determine if build has been done
doTags ?= true
export doTags

#
targetDir ?= ${workDir}/target
export targetDir

extractDir ?= ${workDir}/extract
export extractDir

mwGit ?= ${gerritHead}/mediawiki/core
export mwGit

releaseDir=${workDir}/release
makeRelease=${releaseDir}/make-release/makerelease2.py

defSet=includes/DefaultSettings.php

# The name of the Docker image
imageName ?= mw-ab
export imageName

# The email address to use for the committer
gitCommitEmail ?= $(shell git config --get user.email ||					\
	git config --global --get user.email)
export gitCommitEmail

# The name to use for the committer
gitCommitName ?= $(shell git config --get user.name ||					\
	git config --global --get user.name)
export gitCommitName

getUnknownKeys ?= false

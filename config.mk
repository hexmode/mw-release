# -*- tab-width: 4 -*-
ifndef VERBOSE
.SILENT: # Don't print commands
phpOpts=-d error_reporting=30711
composerQuiet=--quiet
wgetQuiet=-q
maintQuiet=-q
endif

# Following are variables for commands that need args
GIT=git --no-pager --work-tree=${mwDir} --git-dir=${mwDir}/.git
MAKE=make -f $(abspath $(firstword $(MAKEFILE_LIST))) indent="${indent}\> "
WGET=wget ${wgetQuiet}

thisDir=$(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))
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

majorReleaseVer=$(strip $(if $(filter-out ---,${releaseVer}),			\
	$(shell echo ${releaseVer} | cut -d . -f 1).$(shell 				\
	echo ${releaseVer} | cut -d . -f 2),---))
prevMajorVer=${majorReleaseVer}
thisMinorVer=$(strip $(if $(filter-out ---,${releaseVer}),				\
	$(shell echo ${releaseVer} | cut -d . -f 3)))
prevMinorVer=$(strip $(if ${thisMinorVer},								\
	$(shell (echo ${thisMinorVer}; echo 1 - p) | dc )))

# The version to diff against
prevReleaseVer ?= $(if $(filter-out ---,${majorReleaseVer})				\
	,${majorReleaseVer}.${prevMinorVer},---)
# Is thisMinorVer a zero
ifeq "${thisMinorVer}" "0"
	# Fine, find the previous version by fetching the list of
	# previous major version releases, sorting them, and getting
	# the last one.  Startup takes a few ms longer.
	prevMajorVer=$(strip $(if $(filter-out ---,${releaseVer}),			\
		$(shell echo ${releaseVer} | cut -d . -f 1).$(shell				\
		echo ${releaseVer} | (cut -d . -f 2; echo 1 - p ) | dc ),---))
	prevReleaseVer=${prevMajorVer}.$(shell								\
		${WGET} -O - ${releasesUrl}${prevMajorVer}/ |					\
		awk '/mediawiki-${prevMajorVer}[0-9.]*.tar.gz"/ {gsub(			\
		/^.*="mediawiki-${prevMajorVer}.|.tar.gz"[^"]*$$/,				\
		""); print}' | sort -n | tail -1)
endif
export prevReleaseVer

# The message to add when tagging
releaseTagMsg ?= $(strip $(if $(filter-out ---,${releaseVer}),			\
	"MediaWiki v${releaseVer}",---))
export releaseTagMsg

# Revision to tag or branch on
revision ?= HEAD
export revision

# What is the release branch is
relBranch ?= $(strip $(if $(filter-out ---,${releaseVer}),				\
	REL$(shell echo ${releaseVer} | cut -d . -f 1)_$(shell				\
	echo ${releaseVer} | cut -d . -f 2),---))
export relBranch

# Working directory
workDir ?= /src
export workDir

# MediaWiki checkout directory
mwDir=${workDir}/mediawiki
export mwDir

# KeyID to use
keyId ?= $(shell gpgconf --list-options gpg | 							\
	awk -F: '$$1 == "default-key" {print $$10}' | sed s,^.,,)
export keyId

# Continue without signature after downloading
noSigOk ?= false
export noSigOk

# Check for tags to determine if build has been done
doTags ?= true
export doTags

#
targetDir ?= ${thisDir}/target
export targetDir

mwGit ?= ${gerritHead}/mediawiki/core
export mwGit

doNotFail=$(if $(filter-out true,${noSigOk}),true,false)
releaseDir=/opt/release
makeRelease=${releaseDir}/make-release/makerelease2.py

defSet=includes/DefaultSettings.php

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

ifndef VERBOSE
.SILENT: # Don't print commands
phpOpts=-d error_reporting=30711
composerQuiet=--quiet
wgetQuiet=-q
maintQuiet=-q
endif

# Working directory
workDir ?= $(shell test -w /srv && echo /srv || echo `pwd`/srv)
export workDir

#
oldHOME=$(shell getent passwd $$USER | cut -d: -f6)
HOME=${workDir}
export HOME

thisDir=$(patsubst %/,%,$(dir $(abspath $(lastword ${MAKEFILE_LIST}))))
releasesUrl=https://releases.wikimedia.org/mediawiki/

# Following are variables for commands and any standard args
GIT=git --no-pager --work-tree=${mwDir}/${relBranch}						\
		--git-dir=${mwDir}/${relBranch}/.git
MAKE=make -f $(abspath $(firstword ${MAKEFILE_LIST})) indent="${indent}\> "	\
	releaseVer=${releaseVer}
WGET=wget ${wgetQuiet}
makeBranch=${thisDir}/make-branch

# Source of Gerrit
gerritHead ?= https://gerrit.wikimedia.org/r
export gerritHead

# Whether to get submodules or not
fetchSubmodules ?= true
export fetchSubmodules

# What version is being released
releaseVer ?= ---
export releaseVer

# The previous minor version
prevMinorVer ?= -1
export prevMinorVer

# The previous major version
prevMajorVer ?= -1
export prevMajorVer

majorReleaseVer=$(strip $(if $(filter-out ---,${releaseVer}),				\
	$(shell echo ${releaseVer} | cut -d . -f 1).$(shell 					\
	echo ${releaseVer} | cut -d . -f 2),---))

# This minor version
thisMinorVer ?= $(strip $(if $(filter-out ---,${releaseVer}),				\
	$(shell echo ${releaseVer} | cut -d . -f 3),---))
export thisMinorVer

isReleaseCandidate ?= $(if $(filter %-rc,${thisMinorVer}),true,)

ifeq (${isReleaseCandidate},true)
	thisMinorVer=0
endif

prevMajorVer=${majorReleaseVer}
ifeq "${thisMinorVer}" "---"
	prevMinorVer=-1
else
	prevMinorVer=$(shell (echo ${thisMinorVer}; echo 1 - p) | dc )
endif

# The version to diff against
prevReleaseVer ?= $(if $(subst ---,,${majorReleaseVer})						\
	,${majorReleaseVer}.${prevMinorVer},---)
export prevReleaseVer

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
gitCommitName ?= $(shell git config --get user.name ||						\
	git config --global --get user.name)
export gitCommitName

getUnknownKeys ?= false

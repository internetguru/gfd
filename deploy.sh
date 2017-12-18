#!/usr/bin/env bash

shopt -s extglob
shopt -s nocasematch

: ${GFD_MASTER:=master}
: ${GFD_DEVELOP:=dev}
: ${GFD_RELEASE:=release}
: ${GFD_HOTFIXPREFIX:=hotfix-}
: ${GFD_MULTISTABLES:=false}

: ${GFD_HOOKSROOT:=hooks}

##
# stdin  hook json data
# $1  implementation name (e.g. GitHub)
main () {
  echo "$1"
  pwd
  printenv
}

main "$@"

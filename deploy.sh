#!/usr/bin/env bash

shopt -s extglob
shopt -s nocasematch
set -o errtrace

# env fallback
: "${GFD_MASTER:=master}"
: "${GFD_DEVELOP:=dev}"
: "${GFD_RELEASE:=release}"
: "${GFD_HOTFIXPREFIX:=hotfix-}"
: "${GFD_MULTISTABLES:=false}"
: "${GFD_HOOKSROOT:=hooks}"

# utils
err () {
  echo "$(basename "${0}")[error]: $*" >&2
  return 1
}

# $1 branch
updateBranch () {
  echo "updateBranch $1"
}

# $1 tag
updateStable () {
  echo "updateTag $1"
}

# $1 – project id
# $2 – event name
github () {
  local projectid event data ref after refname
  projectid="$1"
  event="$2"
  data="$(cat -)"

  case "$event" in
    push)
      ref="$(jq -r '.ref' <<< "$data")"
      after="$(jq -r '.after' <<< "$data")"
      ;;
    *)
      err "github unsupported event $event" \
        || return 1
      ;;
  esac

  refname="${ref##*/}"

  case "$ref" in
    /ref/heads/*)
      updateBranch "$refname"
      ;;
    /ref/tags/*)
      updateStable "$refname"
      ;;
    *)
      err "unsupported ref format $ref" \
        || return 1
      ;;
  esac
}

# stdin – hook json data
# $1 – project id
# $2 – event name (e.g. push)
# $3 – implementation name (e.g. GitHub)
main () {
  local lock projectid event impl

  # get projectid
  projectid="$1"
  [ -n "$projectid" ] \
    || err "missing projectid argument" \
    || return 2

  # get event
  event="$2"
  [ -n "$event" ] \
    || err "missing event argument" \
    || return 2

  # get implementation name
  impl="$3"
  [ -n "$impl" ] \
    || err "missing implementation name argument" \
    || return 2

  # lock current projectid deploy
  lock="/var/lock/gfd-$projectid.lock"
  unlock () {
    echo "unlock $1"
    rm -f "$1"
  }
  lockfile -2 -r 45 "$lock" \
    || err "Unable to acquire lock" \
    || return 1
  #shellcheck disable=SC2064
  trap "unlock $lock; exit" INT TERM QUIT ERR EXIT

  # call specific implementaiton
  case "$impl" in
    GitHub)
      github "$projectid" "$event"
      ;;
    *)
      err "unsupported implementation $1" \
      || return 1
      ;;
  esac
}

main "$@"


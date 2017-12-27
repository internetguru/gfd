#!/usr/bin/env bash

shopt -s extglob
shopt -s nocasematch

# env fallback
: ${GFD_MASTER:=master}
: ${GFD_DEVELOP:=dev}
: ${GFD_RELEASE:=release}
: ${GFD_HOTFIXPREFIX:=hotfix-}
: ${GFD_MULTISTABLES:=false}
: ${GFD_HOOKSROOT:=hooks}

# utils
err () {
  echo "$(basename "${0}")[error]: $*" >&2
  return 1
}

# $1 – project id
# $2 – event name
github () {
  local projectid event data ref after
  projectid="$1"
  event="$2"
  data="$(cat -)"

  case "$event" in
    push)
      ref="$(jq '.ref' <<< "$data")"
      after="$(jq '.after' <<< "$data")"
      echo "$ref"
      echo "$after"
      ;;
    *)
      err "github unsupported event $event" \
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

  # lock current projectid deploy
  lock="/var/lock/gfd-$projectid.lock"
  unlock () {
    rm -f "$lock"
  }
  lockfile -2 -r 45 "$lock" \
    || err "Unable to acquire lock" \
    || return 1
  trap "unlock; exit" SIGINT SIGTERM

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

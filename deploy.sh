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

# stdin – hook json data
# $1 – implementation name (e.g. GitHub)
# $2 – event name (e.g. push)
main () {

  # utils
  function err {
    echo "$(basename "${0}")[error]: $*" >&2
    return 1
  }

  local lock projectid event

  # lock project deploy
  lock="/var/lock/gfd-$projectid.lock"
  function unlock {
    rm -f "$lock"
  }
  lockfile -2 -r 45 "$lock" \
    || err "Unable to acquire lock" \
    || return 1
  trap "unlock; exit" SIGINT SIGTERM

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
  case "$event" in
    push)
      echo "push event"
      ;;
    *)
      err "unsupported event" \
      || return 1
      ;;
  esac


}

main "$@"

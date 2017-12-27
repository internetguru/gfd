#!/usr/bin/env bash

shopt -s extglob
shopt -s nocasematch
set -o errtrace

# env fallback
: "${GFD_MASTER:=master}"
: "${GFD_DEVELOP:=dev}"
: "${GFD_RELEASE:=release}"
: "${GFD_DEVELOPDIR:=alfa}"
: "${GFD_MASTERDIR:=stable}"
: "${GFD_RELEASEDIR:=beta}"
: "${GFD_HOTFIXPREFIX:=hotfix}"
: "${GFD_MULTISTABLES:=false}"
: "${GFD_HOOKSROOT:=hooks}"
: "${GFD_REMOTE:=origin}"

GFD_GIT_ROOT=.

# utils
err () {
  echo "$(basename "${0}")[error]: $*" >&2
  return 1
}
function git_fetch {
  local out
  out="$(git -C "$GFD_GIT_ROOT" fetch --tags "$GFD_REMOTE" "$1" 2>&1)" \
    || err "$out" \
    || return 1
  echo "$out"
}
function git_checkout {
  local out
  out="$(git -C "$GFD_GIT_ROOT" checkout "$1" 2>&1)" \
    || err "$out" \
    || return 1
  echo "$out"
}

# $1 branch
# $2 commit
updateBranch () {
  case "$1" in
    $GFD_DEVELOP) syncRepo $GFD_DEVELOPDIR "$2" ;;
    $GFD_RELEASE) syncRepo $GFD_RELEASEDIR "$2" ;;
    *)
      # get prefix, e.g. hofix from hotfix-aaa-bbb
      case "${1%%-*}" in
        $GFD_HOTFIXPREFIX) syncRepo "$1" "$2" ;;
      esac
      ;;
  esac
}

# $1 tag
updateStable () {
  echo "updateTag $1"
}

# $1 folder name
# $2 commit or tag
syncRepo () {
  echo "Sync $1 with $2:"
  # create folder if not exists
  [[ ! -d "$1" ]] \
    && { mkdir "$1" || return 1; }
  # set git root
  GFD_GIT_ROOT="$1"
  #git_status_empty \
  #  || return $?
  echo
  echo "- fetching $2..."
  git_fetch "$2" \
    || return $?
  echo
  echo "- checkout to $2..."
  git_checkout "$2" \
    || return $?
}

# $1 – project id
# $2 – event name
github () {
  local projectid event data ref after refname
  projectid="$1"
  event="$2"
  data="$(cat -)"

  # allow only supported events
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
    refs/heads/*)
      updateBranch "$refname" "$after"
      ;;
    refs/tags/*)
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


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
: "${GFD_HOTFIXDIR:=hotfix}"
: "${GFD_HOTFIXPREFIX:=hotfix}"
: "${GFD_MULTISTABLES:=0}"
: "${GFD_HOOKSROOT:=hooks}"
: "${GFD_REMOTE:=origin}"

GFD_GIT_ROOT=.

# utils
err () {
  echo "$(basename "${0}")[error]: $*" >&2
  return 1
}
git_fetch_all () {
  local out
  out="$(git -C "$GFD_GIT_ROOT" fetch --tags --all 2>&1)" \
    || err "$out" \
    || return 1
  echo "$out"
}
git_checkout () {
  local out
  out="$(git -C "$GFD_GIT_ROOT" checkout "$1" 2>&1)" \
    || err "$out" \
    || return 1
  echo "$out"
}
git_clone () {
  local out
  out="$(git clone "$1" "$2" 2>&1)" \
    || err "$out" \
    || return 1
  echo "$out"
}
git_branch_exists () {
  git -C "$GFD_GIT_ROOT" branch -a | grep -q " \(remotes/$GFD_REMOTE/\)\?$1$"
}

# $1 branch
# $2 commit
# $3 clone url
updateBranch () {
  # according to branch..
  case "$1" in
    $GFD_DEVELOP) syncRepo "$GFD_DEVELOPDIR" "$2" "$3" ;;
    $GFD_RELEASE) syncRepo "$GFD_RELEASEDIR" "$2" "$3" ;;
    *)
      # according to prefix, e.g. hofix from hotfix-aaa-bbb
      case "${1%%-*}" in
        $GFD_HOTFIXPREFIX) syncRepo "$1" "$2" "$3" ;;
        # by default do nothing
        *) echo "Nothing to do.." ;;
      esac
      ;;
  esac
}

# $1 tag
# $2 clone url
updateStable () {
  local dirname
  dirname="$GFD_MASTERDIR"

  # multiple stables => dirname=major.minor
  if [[ $GFD_MULTISTABLES == 1 ]]; then
    [[ "$1" =~ v+([0-9]).+([0-9]).+([0-9]) ]] \
      || err "Tag $1 does not match required format" \
      || return 1
    dirname="${1#v}"
    dirname="${dirname%.*}"
  fi

  # sync..
  syncRepo "$dirname" "$1" "$2"

  # update release iff release does not exists
  GFD_GIT_ROOT="$dirname"
  if ! git_branch_exists "$GFD_RELEASE"; then
    syncRepo "$GFD_RELEASEDIR" "$1" "$2"
  fi

  # update hotfix iff hotfix-* does not exists
  if ! git_branch_exists "$GFD_HOTFIXPREFIX-*"; then
    syncRepo "$GFD_HOTFIXDIR" "$1" "$2"
  fi
}

# $1 folder name
# $2 commit or tag
# $3 clone url
syncRepo () {
  local ok
  ok=" ..done"
  echo "Sync $1 with $2:"
  # clone repository iff not exists
  [[ -d "$1" ]] \
    || echo \
    || echo -n "- cloning $3 into $1" \
    || git_clone "$3" "$1" >/dev/null \
    || echo " $ok" \
    || return 1
  # set git root
  GFD_GIT_ROOT="$1"
  # fetch
  echo
  echo -n "- fetching..."
  git_fetch_all >/dev/null \
    || return $?
  echo " $ok"
  # checkout
  echo
  echo -n "- checkout to $2..."
  git_checkout "$2" >/dev/null \
    || return $?
  echo " $ok"
}

# $1 – project id
# $2 – event name
github () {
  local projectid event data ref after refname clone_url
  projectid="$1"
  event="$2"
  data="$(cat -)"

  # allow only supported events
  case "$event" in
    push)
      ref="$(jq -r '.ref' <<< "$data")"
      after="$(jq -r '.after' <<< "$data")"
      clone_url="$(jq -r '.repository .clone_url' <<< "$data")"
      ;;
    *)
      err "github unsupported event $event" \
        || return 1
      ;;
  esac

  refname="${ref##*/}"

  case "$ref" in
    refs/heads/*)
      updateBranch "$refname" "$after" "$clone_url"
      ;;
    refs/tags/*)
      updateStable "$refname" "$clone_url"
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

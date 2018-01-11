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
: "${GFD_HOOKSDIR:=.hooks}"
: "${GFD_REMOTE:=origin}"

# utils
err () {
  echo "$(basename "${0}")[error]: $*" >&2
  return 1
}
git_fetch_all () {
  local out
  out="$(git -C "$GIT_ROOT" fetch --tags --all 2>&1)" \
    || err "$out" \
    || return 1
  echo "$out"
}
git_checkout () {
  local out
  out="$(git -C "$GIT_ROOT" checkout "$1" 2>&1)" \
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
  git -C "$GIT_ROOT" branch -a | grep -q " \(remotes/$GFD_REMOTE/\)\?$1$"
}
git_rev_parse () {
  git -C "$GIT_ROOT" rev-parse "$1"
}
git_rev_list () {
  git -C "$GIT_ROOT" rev-list --all
}
git_rev_exists () {
  [[ -n "$(git -C "$GIT_ROOT" rev-parse --verify "$1" 2>/dev/null)" ]]
}
git_is_new_commit () {
  local current_commit tested_commit rev_list current_pos tested_pos
  current_commit="$(git_rev_parse HEAD)"
  tested_commit="$(git_rev_parse "$1")"
  rev_list="$(git_rev_list)"
  current_pos="$(echo "$rev_list" | grep -n "$current_commit" | cut -d: -f1)"
  tested_pos="$(echo "$rev_list" | grep -n "$tested_commit" | cut -d: -f1)"
  [[ "$tested_pos" -gt "$current_pos" ]]
}

# $1 hookname
call_hook () {
  local hookname

  hookname="$GFD_HOOKSDIR/$PROJECT_ID-$1"

  [[ -f "$hookname" ]] \
    || return 0

  [[ -x "$hookname" ]] \
    || err "File $hookname is not executable" \
    || return 1

  # call hook in separate enviroment
  # TODO pass variables
  echo "@ executing $hookname"
  env -i  "$hookname" \
    || err "$hookname failed" \
    || return 1
  echo "@ $hookname done"
}

# $1 branch
# $2 commit
updateBranch () {
  # according to branch..
  case "$1" in
    $GFD_DEVELOP)
      syncRepo "$GFD_DEVELOPDIR" "$2" \
        || return $?
      ;;
    $GFD_RELEASE)
      syncRepo "$GFD_RELEASEDIR" "$2" \
        || return $?
      ;;
    *)
      # according to prefix, e.g. hofix from hotfix-aaa-bbb
      case "${1%%-*}" in
        $GFD_HOTFIXPREFIX)
          syncRepo "$1" "$2" \
            || return $?
          ;;
        # by default do nothing
        *) echo "Nothing to do.." ;;
      esac
      ;;
  esac
}

# $1 tag
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
    # also sync masterdir
    syncRepo "$GFD_MASTERDIR" "$1" \
      || return $?
  fi

  # sync..
  syncRepo "$dirname" "$1" \
    || return $?

  # update release iff release does not exists
  GIT_ROOT="$dirname"
  git_branch_exists "$GFD_RELEASE" \
    || syncRepo "$GFD_RELEASEDIR" "$1" \
    || return $?

  # update hotfix iff hotfix-* does not exists
  git_branch_exists "$GFD_HOTFIXPREFIX-*" \
    || syncRepo "$GFD_HOTFIXDIR" "$1" \
    || return $?
}

# $1 folder name
# $2 commit or tag
syncRepo () {
  local exit_code

  call_hook "pre-sync" \
    || return $?

  doSyncRepo "$1" "$2"
  exit_code=$?

  call_hook "post-sync" \
    || return $?

  echo
  return $exit_code
}

# $1 folder name
# $2 commit or tag
doSyncRepo () {
  local ok do_fetch

  ok=" ..done"
  do_fetch=1

  echo "Sync $1 with $2:"

  # clone repository iff not exists
  [[ ! -d "$1" ]] \
    && echo -n "- cloning $CLONE_URL into $1" \
    && { git_clone "$CLONE_URL" "$1" >/dev/null || return $?; } \
    && do_fetch=0 \
    && echo " $ok"

  # set git root
  GIT_ROOT="$1"

  # $2 is old commit or tag => return
  # TODO parametrize?
  git_is_new_commit "$2" \
    || err "$1 is already up-to-date" \
    || return 0

  if [[ $do_fetch == 1 ]]; then
    call_hook "pre-fetch" \
      || return $?

    # fetch
    echo -n "- fetching..."
    git_fetch_all >/dev/null \
      || return $?
    echo " $ok"

    call_hook "post-fetch" \
      || return $?
  fi

  call_hook "pre-checkout" \
    || return $?

  # checkout
  echo -n "- checkout to $2..."
  git_checkout "$2" >/dev/null \
    || return $?
  echo " $ok"

  call_hook "post-checkout" \
    || return $?
}

# $1 – event name
github () {
  local event data ref after refname CLONE_URL
  event="$1"
  data="$(cat -)"

  # allow only supported events
  case "$event" in
    push)
      ref="$(jq -r '.ref' <<< "$data")"
      after="$(jq -r '.after' <<< "$data")"
      CLONE_URL="$(jq -r '.repository .clone_url' <<< "$data")"
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
  local lock event impl PROJECT_ID GIT_ROOT

  # get projectid
  PROJECT_ID="$1"
  [ -n "$PROJECT_ID" ] \
    || err "missing project id argument" \
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
  lock="/var/lock/gfd-$PROJECT_ID.lock"
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
      github "$event"
      ;;
    *)
      err "unsupported implementation $1" \
      || return 1
      ;;
  esac
}

main "$@"

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
: "${GFD_FEATUREPREFIX:=feature}"
: "${GFD_HOTFIXPREFIX:=hotfix}"
: "${GFD_MULTISTABLES:=0}"
: "${GFD_HOOKSROOT:=$(dirname $(readlink -f $0))/hooks}"
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
# $1 commit or tag
git_get_files () {
  local out
  out="$(git -C "$GIT_ROOT" diff --oneline --name-only HEAD.."$1" 2>&1)" \
    || err "$out" \
    || return 1
  echo "$out"
}
git_checkout () {
  local out
  out="$(git -C "$GIT_ROOT" checkout "${1:-}" 2>&1)" \
    || err "$out" \
    || return 1
  echo "$out"
}
git_pull () {
  local out
  out="$(git -C "$GIT_ROOT" pull 2>&1)" \
    || err "$out" \
    || return 1
  echo "$out"
}
ggit_reset () {
  local out
  out="$(git -C "$GIT_ROOT" reset "$1" 2>&1)" \
    || err "$out" \
    || return 1
  echo "$out"
}
git_clone () {
  local out
  out="$(git clone -n "$1" "$2" 2>&1)" \
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
  [[ "$tested_pos" -lt "$current_pos" ]]
}

# $1 hookname
# $2 deploydir
call_hook () {
  local hookname

  hookname="$GFD_HOOKSROOT/$PROJECT_ID-$1"

  [[ -f "$hookname" ]] \
    || return 0

  [[ -x "$hookname" ]] \
    || err "File $hookname is not executable" \
    || return 1

  # call hook in separate enviroment
  echo "@ executing $hookname"
  env -i \
    DEPLOY_ROOT="$(pwd)" \
    DEPLOY_DIR="$2" \
    CHANGED_FILES="$CHANGED_FILES" \
    "$hookname" \
    || err "$hookname failed" \
    || return 1
  echo "@ $hookname done"
}

# $1 branch
# $2 commit
update_branch () {
  # according to branch..
  case "$1" in
    $GFD_DEVELOP)
      sync_repo "$GFD_DEVELOPDIR" "$2" \
        || return $?
      ;;
    $GFD_RELEASE)
      sync_repo "$GFD_RELEASEDIR" "$2" \
        || return $?
      ;;
    # support masterdir edit
    $GFD_MASTER)
      sync_repo "$GFD_MASTERDIR" "$2" \
        || return $?
      ;;
    *)
      # according to prefix, e.g. hofix from hotfix-aaa-bbb
      case "${1%%-*}" in
        $GFD_FEATUREPREFIX|$GFD_HOTFIXPREFIX)
          sync_repo "$1" "$2" \
            || return $?
          ;;
        # by default do nothing
        *) echo "Nothing to do.." ;;
      esac
      ;;
  esac
}

# $1 tag
update_stable () {
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
    sync_repo "$GFD_MASTERDIR" "$1" \
      || return $?
  fi

  # sync..
  sync_repo "$dirname" "$1" \
    || return $?

  # update release iff release does not exists
  GIT_ROOT="$dirname"
  git_branch_exists "$GFD_RELEASE" \
    || sync_repo "$GFD_RELEASEDIR" "$1" \
    || return $?
}

# $1 folder name
# $2 commit or tag
sync_repo () {
  local exit_code

  call_hook "pre-sync" "$1" \
    || return $?

  do_sync_repo "$1" "$2"
  exit_code=$?

  call_hook "post-sync" "$1" \
    || return $?

  echo
  return $exit_code
}

# $1 folder name
# $2 commit or tag
do_sync_repo () {
  local ok do_fetch

  ok=" ..done"
  do_fetch=1

  echo "Sync $1 with $2:"

  # clone repository iff not exists
  if [[ ! -d "$1" ]]; then
    echo -n "- cloning $CLONE_URL into $1"
    git_clone "$CLONE_URL" "$1" > /dev/null \
      || return 1
    do_fetch=0
    echo "$ok"
  fi

  # set git root
  GIT_ROOT="$1"

  # $2 is old commit or tag => return
  # TODO enable this check and parametrize?
  # git_is_new_commit "$2" \
  #   || { echo "$1 is already up-to-date" && return 0; }

  if [[ $do_fetch == 1 ]]; then
    call_hook "pre-fetch" "$1" \
      || return $?

    # fetch
    echo -n "- fetching..."
    git_fetch_all >/dev/null \
      || return $?
    echo "$ok"

    CHANGED_FILES="$(git_get_files "$2")"

    call_hook "post-fetch" "$1" \
      || return $?
  fi

  call_hook "pre-checkout" "1" \
    || return $?

  # checkout
  echo -n "- checkout to $2..."
  if git_is_new_commit "$2" && ! git_pull >/dev/null; then
    git_checkout "$2" >/dev/null \
      || return "$?"
  fi
  #git_reset "$2" >/dev/null \
  #  || return $?
  echo "$ok"

  call_hook "post-checkout" "$1" \
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
      CLONE_URL="$(jq -r '.repository .ssh_url' <<< "$data")"
      ;;
    *)
      err "github unsupported event $event" \
        || return 1
      ;;
  esac

  refname="${ref##*/}"

  case "$ref" in
    refs/heads/*)
      update_branch "$refname" "$after" \
        || return $?
      ;;
    refs/tags/*)
      update_stable "$refname" \
        || return $?
      ;;
    *)
      err "unsupported ref format $ref" \
        || return 1
      ;;
  esac
}

# $1 – event name
bitbucket () {
  local event data commit results changes index CLONE_URL
  event="$1"
  data="$(cat -)"

  # allow only supported events
  case "$event" in
    push)
      results=($(echo "$data" | jq -r '.push.changes[].new | .type + ":" + .name'))
      changes=("$(echo "$data" | jq -r '.push.changes[]')")
      ;;
    *)
      err "bitbucket unsupported event $event" \
        || return 1
      ;;
  esac

  # bitbucket can send multiple updates at once
  for index in "${!results[@]}"; do
    item="${results[$index]}"
    IFS=: read -r type name <<< "$item"
    # TODO configurable (https/ssh)
    CLONE_URL="git@bitbucket.org:$(echo "${changes[$index]}" | jq -r '.new.links.html.href' | sed -ne 's~https://[^/]\+/\([^/]\+/[^/]\+\).*~\1~p').git"
    commit="$(echo "${changes[$index]}" | jq -r '.new.target.hash')"
    case "$type" in
      branch)
        update_branch "$name" "$commit" \
          || return $?
        ;;
      tag)
        update_stable "$name" \
          || return $?
        ;;
      *)
        err "unsupported type $type" \
          || return 1
        ;;
    esac
  done
}

# stdin – hook json data
# $1 – project id
# $2 – event name (e.g. push)
# $3 – implementation name (e.g. GitHub)
main () {
  local lock event impl PROJECT_ID GIT_ROOT CHANGED_FILES

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
      github "$event" \
        || return $?
      ;;
    Bitbucket)
      bitbucket "$event" \
        || return $?
      ;;
    *)
      err "unsupported implementation $1" \
      || return 1
      ;;
  esac
}

main "$@"


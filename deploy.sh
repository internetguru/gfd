#!/usr/bin/env bash

shopt -s extglob
shopt -s nocasematch

main () {
  local input
  input="$(cat -)"
  echo "$1"
  echo "$input"
}

main "$@"

#!/usr/bin/env bash
#
# Package the files for a release.
#
# All this does is create zip and tgz files that include the vendor/ directory.

COMPOSER=$(which composer)
if [ -z "$COMPOSER" ]
then
  COMPOSER=$(which composer.phar)
fi
if [ -z "$COMPOSER" ]
then
  echo "X neither composer nor composer.phar found in path. Cannot continue.";
  exit 1;
fi
which tar >/dev/null || { echo "X tar not found in path. Cannot continue."; exit 1; }
which zip >/dev/null || { echo "X zip not found in path. Cannot continue."; exit 1; }
SCRIPT=$(readlink -f "$0")
SCRIPTPATH=$(dirname "$SCRIPT")
cd $SCRIPTPATH/.. || { echo "X hmmm. failed to cd $SCRIPTPATH/.."; exit 1; }
# We should now be in the project root.
CWD=$(pwd)
PROJECT_NAME=$(basename "$CWD")
# Ensure vendor/ is set up.
$COMPOSER install

cd ../ || { echo "X hmm. failed to go up dir."; exit 1; }
[ -w ./ ] || { echo "X cannot write files in " `pwd`; exit 1;}

# Remove any old versions.
rm -f "$PROJECT_NAME".zip "$PROJECT_NAME".tgz

tar czf "$PROJECT_NAME".tgz "$PROJECT_NAME" --exclude='**/.git*' --exclude='**/bin' --exclude='**/tests'
# PR wanted: if you can get zip to behave the same way as tar, please replace this clugey hack!
mkdir temp && cd temp || { echo "X failed making temp dir to create zip file"; exit 1; }
tar xzf ../"$PROJECT_NAME".tgz || { echo "X failed making $PROJECT_NAME.tgz"; exit 1; }
zip -r -q ../"$PROJECT_NAME".zip "$PROJECT_NAME"/ || { echo "X failed making $PROJECT_NAME.zip"; exit 1; }
cd ../
rm -rf temp || { echo "W: created archives OK, but failed to remove the temp dir used in making the zip file."; exit 1; }

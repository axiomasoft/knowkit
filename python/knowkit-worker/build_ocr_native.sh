#!/bin/sh
# Controlled build only; execute in the pinned Debian image, never at runtime.
set -eu
if [ -f /build-ca.crt ]; then
  mkdir -p /etc/ssl/certs
  cp /build-ca.crt /etc/ssl/certs/ca-certificates.crt
  export CURL_CA_BUNDLE=/build-ca.crt
fi
sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources
if ! command -v cmake >/dev/null || ! pkg-config --exists lept; then
  apt-get -o Acquire::https::Timeout=30 -o Acquire::Retries=0 update -qq
  apt-get install -y -qq --no-install-recommends cmake g++ make curl ca-certificates libleptonica-dev pkg-config
fi
fetch() {
  if [ -f "$2" ] && printf '%s  %s\n' "$3" "$2" | sha256sum --check --status; then
    return
  fi
  curl --fail --location --retry 3 --retry-all-errors --max-time 60 -o "$2" "$1"
  printf '%s  %s\n' "$3" "$2" | sha256sum --check -
}
cd /tmp
fetch https://codeload.github.com/tesseract-ocr/tesseract/tar.gz/7e548e6eff172f38f97664afe7f7ce40db36c8d6 tesseract.tar.gz 445603ab1d0a63df552e193a600d9d5c4021aff36e144f3091af149e5efb381a
echo '445603ab1d0a63df552e193a600d9d5c4021aff36e144f3091af149e5efb381a  tesseract.tar.gz' | sha256sum -c -
tar xzf tesseract.tar.gz
cmake -S tesseract-7e548e6eff172f38f97664afe7f7ce40db36c8d6 -B build \
  -DCMAKE_BUILD_TYPE=Release -DBUILD_SHARED_LIBS=OFF -DBUILD_TRAINING_TOOLS=OFF \
  -DDISABLE_ARCHIVE=ON -DDISABLE_CURL=ON -DOPENMP_BUILD=OFF \
  -DCMAKE_EXE_LINKER_FLAGS='-Wl,--disable-new-dtags,-rpath,$ORIGIN/../lib'
cmake --build build -j 2
mkdir -p /out/bin /out/lib /out/tessdata/configs /out/notices
cp build/bin/tesseract /out/bin/
cp tesseract-7e548e6eff172f38f97664afe7f7ce40db36c8d6/tessdata/configs/tsv /out/tessdata/configs/tsv
# Inspect the fresh build, so a retried output directory cannot supply stale libs.
ldd build/bin/tesseract | awk '/=> \/.* / {print $3}' | while read -r dependency; do
  case "$dependency" in
    */libc.so.*|*/libm.so.*|*/libpthread.so.*|*/libdl.so.*) ;;
    *) cp -L "$dependency" /out/lib/ ;;
  esac
done
for language in eng rus osd; do
  case "$language" in
    eng) weight_digest=7d4322bd2a7749724879683fc3912cb542f19906c83bcc1a52132556427170b2 ;;
    rus) weight_digest=e16e5e036cce1d9ec2b00063cf8b54472625b9e14d893a169e2b0dedeb4df225 ;;
    osd) weight_digest=9cf5d576fcc47564f11265841e5ca839001e7e6f38ff7f7aacf46d15a96b00ff ;;
  esac
  fetch "https://raw.githubusercontent.com/tesseract-ocr/tessdata_fast/65727574dfcd264acbb0c3e07860e4e9e9b22185/$language.traineddata" \
    "/out/tessdata/$language.traineddata" "$weight_digest"
done
cd /out/tessdata
sha256sum -c <<'SHA256'
7d4322bd2a7749724879683fc3912cb542f19906c83bcc1a52132556427170b2  eng.traineddata
e16e5e036cce1d9ec2b00063cf8b54472625b9e14d893a169e2b0dedeb4df225  rus.traineddata
9cf5d576fcc47564f11265841e5ca839001e7e6f38ff7f7aacf46d15a96b00ff  osd.traineddata
SHA256
cd /tmp
cp tesseract-7e548e6eff172f38f97664afe7f7ce40db36c8d6/LICENSE /out/notices/Tesseract-LICENSE
fetch https://raw.githubusercontent.com/tesseract-ocr/tessdata_fast/65727574dfcd264acbb0c3e07860e4e9e9b22185/LICENSE /out/notices/tessdata-LICENSE cfc7749b96f63bd31c3c42b5c471bf756814053e847c10f3eb003417bc523d30
cp /usr/share/doc/libleptonica-dev/copyright /out/notices/Leptonica-copyright
dpkg-query -W > /out/build-packages.bom
sha256sum tesseract.tar.gz > /out/source.sha256
g++ --version > /out/compiler.bom
chown -R "${OUTPUT_UID}:${OUTPUT_GID}" /out

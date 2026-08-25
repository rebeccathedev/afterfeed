#!/bin/sh
set -eu
project_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
output_dir="$project_dir/website/dist"
rm -rf "$output_dir"
mkdir -p "$output_dir/afterfeed/assets"
cp "$project_dir/website/root.html" "$output_dir/index.html"
cp "$project_dir/website/index.html" "$output_dir/afterfeed/index.html"
cp "$project_dir/website/site.css" "$output_dir/afterfeed/assets/site.css"
cp "$project_dir/website/_headers" "$output_dir/_headers"
cp "$project_dir/public/brand/afterfeed-mark.svg" "$output_dir/afterfeed/assets/afterfeed-mark.svg"
cp "$project_dir/public/favicon.svg" "$output_dir/afterfeed/assets/favicon.svg"
cp "$project_dir/docs/images/afterfeed-timeline.jpg" "$output_dir/afterfeed/assets/afterfeed-timeline.jpg"
cp "$project_dir/docs/images/afterfeed-on-this-day.jpg" "$output_dir/afterfeed/assets/afterfeed-on-this-day.jpg"
cp "$project_dir/docs/images/afterfeed-share-card.jpg" "$output_dir/afterfeed/assets/afterfeed-share-card.jpg"

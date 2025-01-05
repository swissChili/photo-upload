#!/bin/sh

# Use this script to clear temporary upload data older than some time (30min default)

find chunks -mindepth 1 -mtime +30m -delete

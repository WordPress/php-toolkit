#!/bin/bash

bash test.sh

fswatch -o *.php ./Tests/*.php ./Tests/fixtures/*.php | while read; do
    # Clear the terminal
    clear
    # Run the phpunit tests
    bash test.sh
done

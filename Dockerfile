#-*-tab-width: 4; fill-column: 68; whitespace-line-column: 69 -*-
# vi:shiftwidth=4 tabstop=4 textwidth=68

FROM alpine:3.9
RUN apk add --no-cache	\
    git					\
    gnupg				\
    make				\
    python3				\
    util-linux			\
	wget
RUN pip3 install --upgrade pip git-archive-all requests
COPY Makefile *.mk /
ENTRYPOINT ["make"]

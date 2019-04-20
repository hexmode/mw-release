FROM alpine:3.9
RUN apk add --no-cache	\
        git		\
        gnupg		\
        make		\
        python3		\
        util-linux	\
	wget
RUN pip3 install --upgrade pip requests
COPY Makefile *.mk /
ENTRYPOINT ["make"]

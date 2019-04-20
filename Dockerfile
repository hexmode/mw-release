FROM alpine:3.9
COPY Makefile /
COPY *.mk /
RUN apk add --no-cache	\
        git		\
        gnupg		\
        make		\
        python3		\
        util-linux	\
	wget
RUN pip3 install --upgrade pip
ENTRYPOINT ["make"]

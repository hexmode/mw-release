FROM alpine:3.9
RUN apk add --no-cache make
COPY Makefile /
COPY *.mk /
ENTRYPOINT ["make"]

// Code generated by 'yaegi extract net/smtp'. DO NOT EDIT.

// +build go1.14,!go1.15

package stdlib

import (
	"net/smtp"
	"reflect"
)

func init() {
	Symbols["net/smtp"] = map[string]reflect.Value{
		// function, constant and variable definitions
		"CRAMMD5Auth": reflect.ValueOf(smtp.CRAMMD5Auth),
		"Dial":        reflect.ValueOf(smtp.Dial),
		"NewClient":   reflect.ValueOf(smtp.NewClient),
		"PlainAuth":   reflect.ValueOf(smtp.PlainAuth),
		"SendMail":    reflect.ValueOf(smtp.SendMail),

		// type definitions
		"Auth":       reflect.ValueOf((*smtp.Auth)(nil)),
		"Client":     reflect.ValueOf((*smtp.Client)(nil)),
		"ServerInfo": reflect.ValueOf((*smtp.ServerInfo)(nil)),

		// interface wrapper definitions
		"_Auth": reflect.ValueOf((*_net_smtp_Auth)(nil)),
	}
}

// _net_smtp_Auth is an interface wrapper for Auth type
type _net_smtp_Auth struct {
	WNext  func(fromServer []byte, more bool) (toServer []byte, err error)
	WStart func(server *smtp.ServerInfo) (proto string, toServer []byte, err error)
}

func (W _net_smtp_Auth) Next(fromServer []byte, more bool) (toServer []byte, err error) {
	return W.WNext(fromServer, more)
}
func (W _net_smtp_Auth) Start(server *smtp.ServerInfo) (proto string, toServer []byte, err error) {
	return W.WStart(server)
}